<?php declare(strict_types=1);

namespace PhpParser;

use PhpParser\Layout\SubNodeRef;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Leaf;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitor\LayoutAnnotatingVisitor;

class LayoutTest extends \PHPUnit\Framework\TestCase {
    /** @return Node[] */
    private function annotate(string $code): array {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        return LayoutAnnotatingVisitor::annotate($stmts, $parser->getTokens());
    }

    public function testOf(): void {
        $node = new Expr\Variable('a');
        $this->assertNull(Layout::of($node));

        $node->setAttribute(Layout::ATTRIBUTE, 'not a layout');
        $this->assertNull(Layout::of($node));

        $layout = new Layout([new Leaf\Token('$a')]);
        $node->setAttribute(Layout::ATTRIBUTE, $layout);
        $this->assertSame($layout, Layout::of($node));
    }

    public function testTakeSnapshot(): void {
        $method = new Stmt\ClassMethod('foo', [
            'flags' => Modifiers::PUBLIC | Modifiers::STATIC,
            'byRef' => true,
            'params' => [new Node\Param(new Expr\Variable('a')), new Leaf\Token(','), null],
            'returnType' => null,
        ]);
        $this->assertSame([
            'attrGroups' => [],
            'flags' => Modifiers::PUBLIC | Modifiers::STATIC,
            'byRef' => true,
            'params' => [2 => null],
            'returnType' => null,
            'stmts' => [],
        ], Layout::takeSnapshot($method));

        $this->assertSame(['class.attrGroups' => [], 'class.flags' => 0, 'class.name' => null], array_intersect_key(
            Layout::takeSnapshot(new Stmt\Class_(null), 'class.'),
            ['class.attrGroups' => 1, 'class.flags' => 1, 'class.name' => 1]
        ));

        $int = new Scalar\Int_(16, ['kind' => Scalar\Int_::KIND_HEX, 'rawValue' => '0x10']);
        $this->assertSame(['value' => 16, '@kind' => Scalar\Int_::KIND_HEX, '@rawValue' => '0x10'], Layout::takeSnapshot($int));
    }

    public function testMatches(): void {
        $stmts = $this->annotate("<?php\nfunction foo(\$a) { return \$a; }");
        /** @var Stmt\Function_ $function */
        $function = $stmts[1];
        $layout = Layout::of($function);
        $this->assertNotNull($layout);
        $this->assertTrue($layout->matches($function));

        // Replacing a node sub-node keeps the layout valid.
        $function->name = new Identifier('bar');
        $this->assertTrue($layout->matches($function));

        // Removing array elements keeps the layout valid.
        $origStmts = $function->stmts;
        $function->stmts = [];
        $this->assertTrue($layout->matches($function));
        $function->stmts = $origStmts;

        // Changing a scalar sub-node invalidates the layout.
        $function->byRef = true;
        $this->assertFalse($layout->matches($function));
        $function->byRef = false;
        $this->assertTrue($layout->matches($function));

        // Setting a previously missing node sub-node invalidates the layout.
        $function->returnType = new Identifier('int');
        $this->assertFalse($layout->matches($function));
        $function->returnType = null;
        $this->assertTrue($layout->matches($function));

        // Adding elements to a previously empty array invalidates the layout.
        $function->attrGroups[] = new Node\AttributeGroup([new Node\Attribute(new Name('A'))]);
        $this->assertFalse($layout->matches($function));
        $function->attrGroups = [];
        $this->assertTrue($layout->matches($function));

        // Leaves in previously empty arrays are fine.
        $function->attrGroups[] = new Leaf\Whitespace(' ');
        $this->assertTrue($layout->matches($function));
        $function->attrGroups = [];

        // Removing a referenced node sub-node invalidates the layout.
        /** @var Stmt\Return_ $return */
        $return = $function->stmts[0];
        $returnLayout = Layout::of($return);
        $this->assertTrue($returnLayout->matches($return));
        $return->expr = null;
        $this->assertFalse($returnLayout->matches($return));

        // Changing a tracked attribute invalidates the layout.
        /** @var Scalar\Int_ $int */
        $int = $this->annotate("<?php\n0x10;")[1]->expr;
        $intLayout = Layout::of($int);
        $this->assertTrue($intLayout->matches($int));
        $int->setAttribute('kind', Scalar\Int_::KIND_DEC);
        $this->assertFalse($intLayout->matches($int));

        // A layout does not match a node of a different type.
        $this->assertFalse($layout->matches(new Expr\Variable('x')));
    }

    public function testMatchesAnonymousClass(): void {
        $stmts = $this->annotate("<?php\nnew class(\$a) extends B { public \$p; };");
        /** @var Expr\New_ $new */
        $new = $stmts[1]->expr;
        $layout = Layout::of($new);
        $this->assertTrue($layout->matches($new));

        $new->class->extends = new Name('C');
        $this->assertTrue($layout->matches($new));

        $new->class->flags = Modifiers::READONLY;
        $this->assertFalse($layout->matches($new));
        $new->class->flags = 0;
        $this->assertTrue($layout->matches($new));

        $new->class = new Name('Foo');
        $this->assertFalse($layout->matches($new));
    }

    public function testWithoutLeaves(): void {
        $a = new Expr\Variable('a');
        $b = new Expr\Variable('b');
        $this->assertSame(
            [$a, null, $b],
            Layout::withoutLeaves([new Leaf\Token('('), $a, new Leaf\Token(','), null, new Leaf\Whitespace(' '), $b])
        );
    }

    public function testGetLeavesAndRefs(): void {
        $layout = new Layout([
            $if = new Leaf\Token('if'),
            $ws = new Leaf\Whitespace(' '),
            $ref = new SubNodeRef('cond'),
        ]);
        $this->assertSame([$if, $ws], $layout->getLeaves());
        $this->assertSame([$ref], $layout->getRefs());
    }

    public function testClone(): void {
        $leaf = new Leaf\Whitespace(' ');
        $layout = new Layout([$leaf, new SubNodeRef('cond')]);
        $clone = clone $layout;
        $this->assertNotSame($leaf, $clone->children[0]);
        $this->assertEquals($leaf, $clone->children[0]);
        $clone->children[0]->text = "\n";
        $this->assertSame(' ', $leaf->text);
    }

    public function testJsonSerialize(): void {
        $layout = new Layout([new Leaf\Token('$'), new SubNodeRef('name')], ['name' => 'a']);
        $json = json_decode(json_encode($layout), true);
        $this->assertSame('Layout', $json['nodeType']);
        $this->assertSame('Leaf_Token', $json['children'][0]['nodeType']);
        $this->assertSame('$', $json['children'][0]['text']);
        $this->assertSame(['nodeType' => 'SubNodeRef', 'path' => ['name']], $json['children'][1]);
        $this->assertSame(['name' => 'a'], $json['snapshot']);
    }

    public function testSubNodeRef(): void {
        $if = new Stmt\If_($cond = new Expr\Variable('a'), ['stmts' => $stmts = [new Stmt\Nop()]]);

        $ref = new SubNodeRef('cond');
        $this->assertSame('cond', $ref->getName());
        $this->assertSame('cond', $ref->getKey());
        $this->assertSame($cond, $ref->resolve($if));
        $this->assertSame($if, $ref->resolveOwner($if));

        $ref = new SubNodeRef('stmts');
        $this->assertSame($stmts, $ref->resolve($if));

        $ref = new SubNodeRef('else');
        $this->assertNull($ref->resolve($if));

        $ref = new SubNodeRef('doesNotExist');
        $this->assertNull($ref->resolve($if));

        // Scalar sub-nodes are not resolved.
        $ref = new SubNodeRef('cond', 'name');
        $this->assertSame('cond.name', $ref->getKey());
        $this->assertSame('name', $ref->getName());
        $this->assertSame($cond, $ref->resolveOwner($if));
        $this->assertNull($ref->resolve($if));

        $ref = new SubNodeRef('else', 'stmts');
        $this->assertNull($ref->resolveOwner($if));
        $this->assertNull($ref->resolve($if));

        $this->assertSame(['nodeType' => 'SubNodeRef', 'path' => ['cond', 'name']], (new SubNodeRef('cond', 'name'))->jsonSerialize());
    }

    public function testSubNodeRefRequiresPath(): void {
        $this->expectException(\LogicException::class);
        new SubNodeRef();
    }
}
