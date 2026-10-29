<?php declare(strict_types=1);

namespace PhpParser\NodeVisitor;

use PhpParser\Layout;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Leaf;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Lossless;

class LayoutTransferVisitorTest extends \PHPUnit\Framework\TestCase {
    /** @return array{Node[], Node[]} Original (annotated) and cloned statements */
    private function prepare(string $code): array {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $origStmts = $parser->parse($code);
        // The clone is created before the original is annotated, so it carries no leaves.
        $stmts = (new NodeTraverser(new CloningVisitor()))->traverse($origStmts);
        $origStmts = LayoutAnnotatingVisitor::annotate($origStmts, $parser->getTokens());
        return [$origStmts, $stmts];
    }

    /**
     * @param Node[] $origStmts
     * @param Node[] $stmts
     * @return Node[]
     */
    private function transfer(array $origStmts, array $stmts): array {
        return (new NodeTraverser(new LayoutTransferVisitor($origStmts)))->traverse($stmts);
    }

    /** @param array<Node|null> $items */
    private function describe(array $items): string {
        $parts = [];
        foreach ($items as $item) {
            if ($item instanceof Leaf) {
                $parts[] = substr($item->getType(), 5) . '(' . str_replace("\n", '\n', $item->text) . ')';
            } elseif ($item instanceof Node) {
                $parts[] = $item->getType();
            } else {
                $parts[] = 'null';
            }
        }
        return implode(' ', $parts);
    }

    public function testTransfersLayoutsAndLeaves(): void {
        [$origStmts, $stmts] = $this->prepare("<?php\nfunction f(\$a,  \$b) {\n    foo();\n    bar();\n}\n");
        $this->assertSame('Stmt_Function', $this->describe($stmts));
        $this->assertNull(Layout::of($stmts[0]));

        $stmts = $this->transfer($origStmts, $stmts);
        $this->assertSame('Token(<?php\n) Stmt_Function Whitespace(\n)', $this->describe($stmts));
        $function = $stmts[1];
        $this->assertSame(Layout::of($origStmts[1]), Layout::of($function));
        $this->assertSame('Param Token(,) Whitespace(  ) Param', $this->describe($function->params));
        $this->assertSame('Stmt_Expression Whitespace(\n    ) Stmt_Expression', $this->describe($function->stmts));
        $this->assertSame($origStmts[1]->stmts[0], $function->stmts[0]->getAttribute('origNode'));
    }

    public function testMergesModifiedArrays(): void {
        [$origStmts, $stmts] = $this->prepare("<?php\nfunction f() {\n    // first\n    foo();\n    bar();\n    baz();\n}\n");
        $function = $stmts[0];
        // Remove bar(), insert qux() after foo(), append quux().
        $qux = new Stmt\Expression(new Expr\FuncCall(new Name('qux')));
        $quux = new Stmt\Expression(new Expr\FuncCall(new Name('quux')));
        $function->stmts = [$function->stmts[0], $qux, $function->stmts[2], $quux];

        $stmts = $this->transfer($origStmts, $stmts);
        $function = $stmts[1];
        $this->assertSame(
            // bar() is replaced by qux(), which inherits its leaves; quux() is appended without leaves.
            'Stmt_Expression Whitespace(\n    ) Stmt_Expression Whitespace(\n    ) Stmt_Expression Stmt_Expression',
            $this->describe($function->stmts)
        );
        // The leaves in front of the first statement stay in the layout of the function.
        $this->assertStringContainsString('Comment(// first)', $this->describe(Layout::of($function)->children));
        $this->assertSame(
            "<?php\nfunction f() {\n    // first\n    foo();\n    qux();\n    baz();\n    quux();\n}\n",
            (new Lossless())->prettyPrintFile($stmts)
        );
    }

    public function testMovedNodesAreNotOriginalChildren(): void {
        [$origStmts, $stmts] = $this->prepare("<?php\nfoo();\nbar();\n");
        $stmts = [$stmts[1], $stmts[0]];
        $stmts = $this->transfer($origStmts, $stmts);

        $printer = new Lossless();
        $this->assertSame("<?php\nbar();\nfoo();\n", $printer->prettyPrintFile($stmts));
    }

    public function testKeepsExistingLeaves(): void {
        // Cloning an annotated AST clones its layouts and leaves, so there is nothing to transfer.
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $code = "<?php\nfoo( \$a );\n";
        $origStmts = LayoutAnnotatingVisitor::annotate($parser->parse($code), $parser->getTokens());
        $stmts = (new NodeTraverser(new CloningVisitor()))->traverse($origStmts);
        $this->assertSame($stmts, $this->transfer($origStmts, $stmts));
        $this->assertSame($code, (new Lossless())->prettyPrintFile($stmts));
    }

    public function testFallsBackWhenAllElementsAreRemoved(): void {
        [$origStmts, $stmts] = $this->prepare("<?php\nfunction f() {\n    foo();\n}\n");
        $stmts[0]->stmts = [];
        $stmts = $this->transfer($origStmts, $stmts);
        $this->assertNull(Layout::of($stmts[1]));
        $this->assertSame("<?php\nfunction f()\n{\n}\n", (new Lossless())->prettyPrintFile($stmts));
    }
}
