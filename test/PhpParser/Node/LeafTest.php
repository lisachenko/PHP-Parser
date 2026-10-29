<?php declare(strict_types=1);

namespace PhpParser\Node;

use PhpParser\Comment as CommentValue;
use PhpParser\Token as LexerToken;

class LeafTest extends \PHPUnit\Framework\TestCase {
    public function testFromToken(): void {
        $whitespace = Leaf::fromToken(new LexerToken(\T_WHITESPACE, "\n    ", 2, 10), 3);
        $this->assertInstanceOf(Leaf\Whitespace::class, $whitespace);
        $this->assertSame("\n    ", $whitespace->text);
        $this->assertSame("\n    ", $whitespace->getText());
        $this->assertSame("\n    ", (string) $whitespace);
        $this->assertSame(2, $whitespace->getStartLine());
        $this->assertSame(3, $whitespace->getEndLine());
        $this->assertSame(10, $whitespace->getStartFilePos());
        $this->assertSame(14, $whitespace->getEndFilePos());
        $this->assertSame(3, $whitespace->getStartTokenPos());
        $this->assertSame(3, $whitespace->getEndTokenPos());
        $this->assertTrue($whitespace->isTrivia());
        $this->assertSame('Leaf_Whitespace', $whitespace->getType());
        $this->assertSame(['text'], $whitespace->getSubNodeNames());

        $comment = Leaf::fromToken(new LexerToken(\T_COMMENT, '// foo', 1, 0), 0);
        $this->assertInstanceOf(Leaf\Comment::class, $comment);
        $this->assertTrue($comment->isTrivia());
        $this->assertSame('Leaf_Comment', $comment->getType());

        $docComment = Leaf::fromToken(new LexerToken(\T_DOC_COMMENT, '/** foo */', 1, 0), 0);
        $this->assertInstanceOf(Leaf\Comment::class, $docComment);

        $token = Leaf::fromToken(new LexerToken(\T_FUNCTION, 'function', 1, 0), 0);
        $this->assertInstanceOf(Leaf\Token::class, $token);
        $this->assertFalse($token->isTrivia());
        $this->assertSame('Leaf_Token', $token->getType());
    }

    public function testWhitespaceHasNewline(): void {
        $this->assertFalse((new Leaf\Whitespace("  \t"))->hasNewline());
        $this->assertTrue((new Leaf\Whitespace("\n"))->hasNewline());
        $this->assertTrue((new Leaf\Whitespace("\r\n  "))->hasNewline());
        $this->assertTrue((new Leaf\Whitespace("\r"))->hasNewline());
    }

    public function testIsDocComment(): void {
        $this->assertTrue((new Leaf\Comment('/** Doc */'))->isDocComment());
        $this->assertTrue((new Leaf\Comment("/**\n * Doc\n */"))->isDocComment());
        $this->assertFalse((new Leaf\Comment('/**/'))->isDocComment());
        $this->assertFalse((new Leaf\Comment('/* Not doc */'))->isDocComment());
        $this->assertFalse((new Leaf\Comment('// Line'))->isDocComment());
        $this->assertFalse((new Leaf\Comment('# Hash'))->isDocComment());
    }

    public function testToComment(): void {
        $leaf = Leaf::fromToken(new LexerToken(\T_DOC_COMMENT, "/** Doc\n */", 3, 20), 7);
        $comment = $leaf->toComment();
        $this->assertInstanceOf(CommentValue\Doc::class, $comment);
        $this->assertSame("/** Doc\n */", $comment->getText());
        $this->assertSame(3, $comment->getStartLine());
        $this->assertSame(4, $comment->getEndLine());
        $this->assertSame(20, $comment->getStartFilePos());
        $this->assertSame(30, $comment->getEndFilePos());
        $this->assertSame(7, $comment->getStartTokenPos());
        $this->assertSame(7, $comment->getEndTokenPos());

        $comment = (new Leaf\Comment('// foo'))->toComment();
        $this->assertNotInstanceOf(CommentValue\Doc::class, $comment);
        $this->assertSame('// foo', $comment->getText());
        $this->assertSame(-1, $comment->getStartLine());
    }
}
