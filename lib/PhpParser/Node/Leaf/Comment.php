<?php declare(strict_types=1);

namespace PhpParser\Node\Leaf;

use PhpParser\Comment as CommentValue;
use PhpParser\Node\Leaf;

/**
 * A comment stored as a leaf node, including its delimiters.
 *
 * This is the in-tree counterpart of the PhpParser\Comment objects that are available through
 * the "comments" attribute of the node following the comment.
 */
class Comment extends Leaf {
    public function isTrivia(): bool {
        return true;
    }

    /** Whether this is a doc comment (starting with "/**" followed by whitespace). */
    public function isDocComment(): bool {
        return 1 === preg_match('~\A/\*\*\s~', $this->text);
    }

    /**
     * Converts the leaf into a comment object, as used by the "comments" attribute.
     */
    public function toComment(): CommentValue {
        $class = $this->isDocComment() ? CommentValue\Doc::class : CommentValue::class;
        return new $class(
            $this->text,
            $this->getStartLine(), $this->getStartFilePos(), $this->getStartTokenPos(),
            $this->getEndLine(), $this->getEndFilePos(), $this->getEndTokenPos()
        );
    }

    public function getType(): string {
        return 'Leaf_Comment';
    }
}
