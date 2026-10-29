<?php declare(strict_types=1);

namespace PhpParser\NodeVisitor;

use PhpParser\Layout;
use PhpParser\Layout\SubNodeRef;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Leaf;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;

class LayoutAnnotatingVisitorTest extends \PHPUnit\Framework\TestCase {
    /** @return array{Node[], \PhpParser\Token[]} */
    private function parse(string $code): array {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        return [$stmts, $parser->getTokens()];
    }

    /** @return Node[] */
    private function annotate(string $code): array {
        [$stmts, $tokens] = $this->parse($code);
        return LayoutAnnotatingVisitor::annotate($stmts, $tokens);
    }

    /**
     * Describes a list of layout children / array elements in a compact form.
     *
     * @param array<Leaf|SubNodeRef|Node|null> $items
     */
    private function describe(array $items): string {
        $parts = [];
        foreach ($items as $item) {
            if ($item instanceof Leaf) {
                $parts[] = substr($item->getType(), 5) . '(' . str_replace("\n", '\n', $item->text) . ')';
            } elseif ($item instanceof SubNodeRef) {
                $parts[] = '<' . $item->getKey() . '>';
            } elseif ($item instanceof Node) {
                $parts[] = $item->getType();
            } else {
                $parts[] = 'null';
            }
        }
        return implode(' ', $parts);
    }

    public function testLayoutStructure(): void {
        $stmts = $this->annotate("<?php\n\nif (\$a) {\n    foo(\$b,  \$c);\n}\n");
        $this->assertSame('Token(<?php\n) Whitespace(\n) Stmt_If Whitespace(\n)', $this->describe($stmts));

        /** @var Stmt\If_ $if */
        $if = $stmts[2];
        $this->assertSame(
            'Token(if) Whitespace( ) Token(() <cond> Token()) Whitespace( ) Token({) Whitespace(\n    ) <stmts> Whitespace(\n) Token(})',
            $this->describe(Layout::of($if)->children)
        );
        $this->assertSame('Token($a)', $this->describe(Layout::of($if->cond)->children));
        $this->assertSame('Stmt_Expression', $this->describe($if->stmts));

        /** @var Expr\FuncCall $call */
        $call = $if->stmts[0]->expr;
        $this->assertSame('<name> Token(() <args> Token())', $this->describe(Layout::of($call)->children));
        $this->assertSame('Arg Token(,) Whitespace(  ) Arg', $this->describe($call->args));
        $this->assertSame('<value>', $this->describe(Layout::of($call->args[0])->children));

        // Every node got a layout.
        $visitor = new class () extends \PhpParser\NodeVisitorAbstract {
            public int $count = 0;
            public function enterNode(Node $node) {
                if (!$node instanceof Leaf) {
                    \PHPUnit\Framework\Assert::assertNotNull(Layout::of($node), $node->getType());
                    $this->count++;
                }
                return null;
            }
        };
        (new NodeTraverser($visitor))->traverse($stmts);
        // If, Variable, Expression, FuncCall, Name, Arg, Variable, Arg, Variable
        $this->assertSame(9, $visitor->count);
    }

    public function testCommentsAndSeparatorsAreLeaves(): void {
        $code = "<?php\nclass A\n{\n    // c1\n    public \$a;\n\n    /** Doc */\n    public function f() {}\n}\n";
        $stmts = $this->annotate($code);
        /** @var Stmt\Class_ $class */
        $class = $stmts[1];
        $this->assertSame(
            'Token(class) Whitespace( ) <name> Whitespace(\n) Token({) Whitespace(\n    ) Comment(// c1) Whitespace(\n    ) <stmts> Whitespace(\n) Token(})',
            $this->describe(Layout::of($class)->children)
        );
        $this->assertSame(
            'Stmt_Property Whitespace(\n\n    ) Comment(/** Doc */) Whitespace(\n    ) Stmt_ClassMethod',
            $this->describe($class->stmts)
        );

        /** @var Leaf\Comment $docComment */
        $docComment = $class->stmts[2];
        $this->assertTrue($docComment->isDocComment());
        // The comments attribute is still populated.
        $this->assertSame('/** Doc */', $class->stmts[4]->getDocComment()->getText());
        $this->assertSame(7, $docComment->getStartLine());
        $this->assertSame(7, $docComment->toComment()->getStartLine());
    }

    public function testLeafPositions(): void {
        $stmts = $this->annotate("<?php\n\$a;  \n");
        /** @var Leaf\Whitespace $ws */
        $ws = $stmts[2];
        $this->assertSame(2, $ws->getStartLine());
        $this->assertSame(3, $ws->getEndLine());
        $this->assertSame(9, $ws->getStartFilePos());
        $this->assertSame(11, $ws->getEndFilePos());
        $this->assertSame(3, $ws->getStartTokenPos());
        $this->assertSame(3, $ws->getEndTokenPos());
    }

    public function testAnonymousClass(): void {
        $stmts = $this->annotate("<?php\n\$o = new class(\$a) extends B implements C {\n    public \$p;\n};\n");
        /** @var Expr\New_ $new */
        $new = $stmts[1]->expr->expr;
        $this->assertSame(
            'Token(new) Whitespace( ) Token(class) Token(() <args> Token()) Whitespace( ) Token(extends) Whitespace( ) <class.extends>'
            . ' Whitespace( ) Token(implements) Whitespace( ) <class.implements> Whitespace( ) Token({) Whitespace(\n    ) <class.stmts> Whitespace(\n) Token(})',
            $this->describe(Layout::of($new)->children)
        );
        $this->assertNull(Layout::of($new->class));
        $this->assertNotNull(Layout::of($new->class->extends));
        $this->assertNotNull(Layout::of($new->class->stmts[0]));
        $snapshot = Layout::of($new)->getSnapshot();
        $this->assertArrayNotHasKey('class.name', $snapshot);
        $this->assertArrayHasKey('class.flags', $snapshot);
    }

    public function testZeroLengthNop(): void {
        $stmts = $this->annotate("<?php\n// comment\n");
        // The zero-length nop is positioned directly after the comment.
        $this->assertSame('Token(<?php\n) Comment(// comment) Stmt_Nop Whitespace(\n)', $this->describe($stmts));
        $this->assertSame('', $this->describe(Layout::of($stmts[2])->children));
        $this->assertTrue(Layout::of($stmts[2])->matches($stmts[2]));

        $stmts = $this->annotate("<?php\n{\n    /* comment */\n}\n");
        /** @var Stmt\Block $block */
        $block = $stmts[1];
        $this->assertSame(
            'Token({) Whitespace(\n    ) Comment(/* comment */) <stmts> Whitespace(\n) Token(})',
            $this->describe(Layout::of($block)->children)
        );
        $this->assertSame('Stmt_Nop', $this->describe($block->stmts));
        $this->assertSame('', $this->describe(Layout::of($block->stmts[0])->children));
    }

    public function testEmptyFile(): void {
        $this->assertSame([], $this->annotate(''));
        $stmts = $this->annotate("<?php\n");
        $this->assertSame('Token(<?php\n)', $this->describe($stmts));
    }

    public function testInlineHtml(): void {
        $code = "Hello <?php echo 'x' ?> world\n<?php\n\$a;";
        $stmts = $this->annotate($code);
        $this->assertSame(
            'Stmt_InlineHTML Token(<?php ) Stmt_Echo Stmt_InlineHTML Token(<?php\n) Stmt_Expression',
            $this->describe($stmts)
        );
        $this->assertSame('Token(Hello )', $this->describe(Layout::of($stmts[0])->children));
        $this->assertSame('Token(echo) Whitespace( ) <exprs> Whitespace( ) Token(?>)', $this->describe(Layout::of($stmts[2])->children));
    }

    public function testNodesWithoutPositionsGetNoLayout(): void {
        [$stmts, $tokens] = $this->parse("<?php\nfoo(\$a);\n");
        /** @var Expr\FuncCall $call */
        $call = $stmts[0]->expr;
        $call->args[0]->value = new Expr\Variable('b');

        $stmts = LayoutAnnotatingVisitor::annotate($stmts, $tokens);
        $this->assertSame('Token(<?php\n) Stmt_Expression Whitespace(\n)', $this->describe($stmts));
        $this->assertNotNull(Layout::of($stmts[1]));
        $this->assertNotNull(Layout::of($call));
        $this->assertNull(Layout::of($call->args[0]));
        $this->assertNull(Layout::of($call->args[0]->value));
    }

    public function testTopLevelWithoutPositionsIsLeftAlone(): void {
        [$stmts, $tokens] = $this->parse("<?php\nfoo();\n");
        $stmts[] = new Stmt\Nop();
        $result = LayoutAnnotatingVisitor::annotate($stmts, $tokens);
        $this->assertSame('Stmt_Expression Stmt_Nop', $this->describe($result));
        $this->assertNotNull(Layout::of($result[0]));
        $this->assertNull(Layout::of($result[1]));
    }

    public function testAnnotatingTwiceIsNoop(): void {
        [$stmts, $tokens] = $this->parse("<?php\nif (\$a) { foo(\$b, \$c); }\n");
        $once = LayoutAnnotatingVisitor::annotate($stmts, $tokens);
        $layout = Layout::of($once[1]);
        $twice = LayoutAnnotatingVisitor::annotate($once, $tokens);
        $this->assertSame($once, $twice);
        $this->assertSame($layout, Layout::of($twice[1]));
        $this->assertSame('Arg Token(,) Whitespace( ) Arg', $this->describe($twice[1]->stmts[0]->expr->args));
    }

    public function testListDestructuringWithSkippedElements(): void {
        $stmts = $this->annotate("<?php\nlist(, \$a, , \$b) = \$c;\n");
        /** @var Expr\List_ $list */
        $list = $stmts[1]->expr->var;
        $this->assertSame('Token(list) Token(() Token(,) Whitespace( ) <items> Token())', $this->describe(Layout::of($list)->children));
        // Skipped elements have no position, so the leaves are attached to the next item.
        $this->assertSame('null ArrayItem null Token(,) Whitespace( ) Token(,) Whitespace( ) ArrayItem', $this->describe($list->items));
        $this->assertTrue(Layout::of($list)->matches($list));
    }
}
