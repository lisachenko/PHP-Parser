<?php declare(strict_types=1);

namespace PhpParser\Node\Leaf;

use PhpParser\Node\Leaf;

/**
 * A syntactic token that is not represented by a dedicated node in the AST: keywords,
 * operators, punctuation, PHP open/close tags, the raw text of literals, etc.
 */
class Token extends Leaf {
    public function getType(): string {
        return 'Leaf_Token';
    }
}
