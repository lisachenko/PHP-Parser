<?php declare(strict_types=1);

namespace PhpParser\Node\Leaf;

use PhpParser\Node\Leaf;

/** Whitespace between two tokens (spaces, tabs and newlines). */
class Whitespace extends Leaf {
    public function isTrivia(): bool {
        return true;
    }

    /** Whether the whitespace contains at least one line break. */
    public function hasNewline(): bool {
        return false !== strpos($this->text, "\n") || false !== strpos($this->text, "\r");
    }

    public function getType(): string {
        return 'Leaf_Whitespace';
    }
}
