<?php declare(strict_types=1);

namespace PhpParser;

use PhpParser\Node\Expr;
use PhpParser\Node\Leaf;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\LayoutAnnotatingVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\PrettyPrinter\Lossless;
use PhpParser\PrettyPrinter\Standard;

class LosslessPrinterTest extends CodeTestAbstract {
    private function createParser(?string $version): Parser {
        $factory = new ParserFactory();
        $version = $version === null
            ? PhpVersion::getNewestSupported() : PhpVersion::fromString($version);
        return $factory->createForVersion($version);
    }

    /** @return Node[] */
    private function annotate(string $code, ?Parser $parser = null): array {
        $parser = $parser ?? $this->createParser(null);
        $stmts = $parser->parse($code);
        return LayoutAnnotatingVisitor::annotate($stmts, $parser->getTokens());
    }

    /**
     * @dataProvider provideTestRoundTrip
     */
    public function testRoundTrip($name, $code, $expected, $modeLine): void {
        /*
         * The lossless printer must reproduce the input exactly for all parser and pretty
         * printer tests, and the standard printer must produce the same output with and
         * without leaves in the AST.
         */
        $modes = $this->parseModeLine($modeLine);
        $parser = $this->createParser($modes['version'] ?? null);

        $errors = new ErrorHandler\Collecting();
        $stmts = $parser->parse($code, $errors);
        if ($stmts === null || $errors->hasErrors() || $code === '') {
            // Can't do a lossless print on a file with errors. An empty file has no leaves
            // either, so it is printed by the standard printer.
            $this->addToAssertionCount(1);
            return;
        }

        $annotated = LayoutAnnotatingVisitor::annotate($stmts, $parser->getTokens());
        $printer = new Lossless();
        $this->assertSame($code, $printer->prettyPrintFile($annotated), $name);

        $standard = new Standard();
        $plain = $parser->parse($code);
        $this->assertSame($standard->prettyPrintFile($plain), $standard->prettyPrintFile($annotated), $name);
    }

    public static function provideTestRoundTrip() {
        return array_merge(
            self::getTests(__DIR__ . '/../code/prettyPrinter', 'test'),
            self::getTests(__DIR__ . '/../code/parser', 'test')
        );
    }

    public function testRoundTripLibrarySources(): void {
        $parser = $this->createParser(null);
        $printer = new Lossless();
        $count = 0;
        foreach (filesInDir(__DIR__ . '/../../lib', 'php') as $fileName => $code) {
            $annotated = $this->annotate($code, $parser);
            $this->assertSame($code, $printer->prettyPrintFile($annotated), $fileName);
            $count++;
        }
        $this->assertGreaterThan(100, $count);
    }

    /**
     * @dataProvider provideTestModification
     */
    public function testModification($name, $code, $modification, $expected, $modeLine): void {
        $modes = $this->parseModeLine($modeLine);
        $parser = $this->createParser($modes['version'] ?? null);
        $stmts = $this->annotate($code, $parser);

        /** @var callable $fn */
        eval(<<<CODE
use PhpParser\Comment;
use PhpParser\Layout;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Leaf;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\Modifiers;
\$fn = function(&\$stmts) { $modification };
CODE
        );
        $fn($stmts);

        $printer = new Lossless();
        $this->assertSame(canonicalize($expected), canonicalize($printer->prettyPrintFile($stmts)), $name);
    }

    public static function provideTestModification() {
        return self::getTests(__DIR__ . '/../code/layout', 'test', 3);
    }

    public function testWithoutLeavesBehavesLikeStandardPrinter(): void {
        $parser = $this->createParser(null);
        $code = "<?php\n\nfunction   foo( \$a ) { return \$a; }";
        $stmts = $parser->parse($code);
        $printer = new Lossless();
        $standard = new Standard();
        $this->assertSame($standard->prettyPrintFile($stmts), $printer->prettyPrintFile($stmts));
        $this->assertSame($standard->prettyPrint($stmts), $printer->prettyPrint($stmts));
        $this->assertSame($standard->prettyPrintFile([]), $printer->prettyPrintFile([]));

        // Nodes with layouts are also used when the top-level array has no leaves.
        $annotated = $this->annotate($code, $parser);
        $this->assertSame(
            "<?php\n\nfunction   foo( \$a ) { return \$a; }",
            $printer->prettyPrintFile(Layout::withoutLeaves($annotated))
        );
    }

    public function testPrettyPrintStripsOpenTag(): void {
        $printer = new Lossless();
        $stmts = $this->annotate("<?php\n\nfoo( 1 );\nbar(  );\n");
        $this->assertSame("foo( 1 );\nbar(  );\n", $printer->prettyPrint($stmts));
        $this->assertSame("foo( 1 )", $printer->prettyPrintExpr($stmts[2]->expr));
    }

    public function testNameResolver(): void {
        $code = "<?php\nnamespace Foo;\nuse Bar\\Baz, Qux\\Quux;\nclass A extends Baz implements Quux, \\Countable {\n"
            . "    use T1, T2 { T1::x insteadof T2; }\n"
            . "    public function f(Baz|Quux \$a, #[Attr] int \$b): Quux {}\n"
            . "    const C = [1];\n"
            . "}\n"
            . "try {} catch (Baz|Quux \$e) {}\n";
        $stmts = $this->annotate($code);
        $traverser = new NodeTraverser(new NameResolver());
        $stmts = $traverser->traverse($stmts);

        $printer = new Lossless();
        $this->assertSame(
            "<?php\nnamespace Foo;\nuse Bar\\Baz, Qux\\Quux;\nclass A extends \\Bar\\Baz implements \\Qux\\Quux, \\Countable {\n"
            . "    use \\Foo\\T1, \\Foo\\T2 { \\Foo\\T1::x insteadof \\Foo\\T2; }\n"
            . "    public function f(\\Bar\\Baz|\\Qux\\Quux \$a, #[\\Foo\\Attr] int \$b): \\Qux\\Quux {}\n"
            . "    const C = [1];\n"
            . "}\n"
            . "try {} catch (\\Bar\\Baz|\\Qux\\Quux \$e) {}\n",
            $printer->prettyPrintFile($stmts)
        );

        /** @var Stmt\Class_ $class */
        $class = Layout::withoutLeaves($stmts[1]->stmts)[1];
        $this->assertSame('Foo\A', $class->namespacedName->toString());
        $this->assertSame('Bar\Baz', $class->extends->toString());
    }

    public function testConstExprEvaluator(): void {
        $stmts = $this->annotate("<?php\nconst A = [1,  2, 'x' => 3];\n");
        $evaluator = new ConstExprEvaluator();
        $this->assertSame([1, 2, 'x' => 3], $evaluator->evaluateSilently($stmts[1]->consts[0]->value));
    }

    public function testJsonRoundTrip(): void {
        $code = "<?php\n\$x = foo(\$a,   \$b) + new class { public \$p; };\n";
        $stmts = $this->annotate($code);
        $json = json_encode($stmts, JSON_PRETTY_PRINT);
        $this->assertStringContainsString('"nodeType": "Layout"', $json);
        $this->assertStringContainsString('"nodeType": "Leaf_Whitespace"', $json);
        $this->assertStringContainsString('"nodeType": "SubNodeRef"', $json);

        $decoded = (new JsonDecoder())->decode($json);
        $dumper = new NodeDumper(['dumpOtherAttributes' => true]);
        $this->assertSame($dumper->dump($stmts), $dumper->dump($decoded));
        $this->assertSame($code, (new Lossless())->prettyPrintFile($decoded));
    }

    public function testCloningVisitor(): void {
        $code = "<?php\nfoo();\n\nbar();\n";
        $stmts = $this->annotate($code);
        $traverser = new NodeTraverser(new CloningVisitor());
        $cloned = $traverser->traverse($stmts);

        $printer = new Lossless();
        $this->assertSame($code, $printer->prettyPrintFile($cloned));

        // Leaves in arrays and in layouts are cloned, so the trees can be modified independently.
        $cloned[2]->text = "\n";
        Layout::of($cloned[1]->expr)->children[1]->text = '  (';
        $this->assertSame("<?php\nfoo  ();\nbar();\n", $printer->prettyPrintFile($cloned));
        $this->assertSame($code, $printer->prettyPrintFile($stmts));
        $this->assertSame($stmts[1], $cloned[1]->getAttribute('origNode'));
    }

    public function testFormatPreservingPrintStillWorks(): void {
        $parser = $this->createParser(null);
        $code = "<?php\nfunction foo(\$a)\n    { return \$a; }\n";
        $oldStmts = $parser->parse($code);
        $oldTokens = $parser->getTokens();
        $newStmts = (new NodeTraverser(new CloningVisitor()))->traverse($oldStmts);
        $newStmts[0]->name = new Node\Identifier('bar');

        $printer = new Lossless();
        $this->assertSame(
            "<?php\nfunction bar(\$a)\n    { return \$a; }\n",
            $printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens)
        );
    }

    public function testNodeDumper(): void {
        $stmts = $this->annotate("<?php\nif (\$a) {}");
        $dumper = new NodeDumper(['dumpOtherAttributes' => true]);
        $this->assertSame(
            'array(
    0: Leaf_Token(
        text: <?php

    )
    1: Stmt_If(
        cond: Expr_Variable(
            name: a
            layout: Layout(
                0: Leaf_Token(
                    text: $a
                )
            )
        )
        stmts: array(
        )
        elseifs: array(
        )
        else: null
        layout: Layout(
            0: Leaf_Token(
                text: if
            )
            1: Leaf_Whitespace(
                text:
            )
            2: Leaf_Token(
                text: (
            )
            3: SubNodeRef(cond)
            4: Leaf_Token(
                text: )
            )
            5: Leaf_Whitespace(
                text:
            )
            6: Leaf_Token(
                text: {
            )
            7: Leaf_Token(
                text: }
            )
        )
    )
)',
            canonicalize($dumper->dump($stmts))
        );

        // Without dumpOtherAttributes, layouts are not dumped, but leaves in arrays are.
        $this->assertSame(
            'array(
    0: Leaf_Token(
        text: <?php

    )
    1: Stmt_If(
        cond: Expr_Variable(
            name: a
        )
        stmts: array(
        )
        elseifs: array(
        )
        else: null
    )
)',
            canonicalize((new NodeDumper())->dump($stmts))
        );
    }

    public function testWindowsNewlines(): void {
        $code = "<?php\r\n\r\nfunction foo()\r\n{\r\n    return 1;\r\n}\r\n";
        $stmts = $this->annotate($code);
        $printer = new Lossless();
        $this->assertSame($code, $printer->prettyPrintFile($stmts));

        $stmts[2]->stmts[] = new Stmt\Expression(new Expr\FuncCall(new Name('bar')));
        $this->assertSame(
            "<?php\r\n\r\nfunction foo()\r\n{\r\n    return 1;\n    bar();\r\n}\r\n",
            $printer->prettyPrintFile($stmts)
        );
        $printer = new Lossless(['newline' => "\r\n"]);
        $this->assertSame(
            "<?php\r\n\r\nfunction foo()\r\n{\r\n    return 1;\r\n    bar();\r\n}\r\n",
            $printer->prettyPrintFile($stmts)
        );
    }

    public function testLeavesArePrintedVerbatim(): void {
        $printer = new Lossless();
        $stmts = [
            new Leaf\Token("<?php\n"),
            new Stmt\Expression(new Expr\FuncCall(new Name('foo'))),
            new Leaf\Whitespace("\n\n"),
            new Leaf\Comment('// done'),
            new Leaf\Whitespace("\n"),
        ];
        $this->assertSame("<?php\nfoo();\n\n// done\n", $printer->prettyPrintFile($stmts));
    }
}
