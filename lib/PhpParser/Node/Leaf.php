<?php declare(strict_types=1);

namespace PhpParser\Node;

use PhpParser\Node\Leaf\Comment;
use PhpParser\Node\Leaf\Token;
use PhpParser\Node\Leaf\Whitespace;
use PhpParser\NodeAbstract;
use PhpParser\Token as LexerToken;

/**
 * A leaf node holding a verbatim piece of source code that carries no semantics of its own:
 * whitespace, comments and syntactic tokens (keywords, operators, punctuation, PHP tags, ...).
 *
 * Leaf nodes are the building blocks of lossless layouts (see PhpParser\Layout). Similar to
 * leaf elements in IntelliJ's PSI, they are stored right next to the "functional" nodes: in the
 * "layout" attribute of a node and in-between the elements of node arrays (statement lists,
 * argument lists, ...). The pretty printers ignore leaf nodes, except for the
 * PhpParser\PrettyPrinter\Lossless printer, which prints them verbatim.
 */
abstract class Leaf extends NodeAbstract {
    /** @var string Verbatim source text of the leaf */
    public string $text;

    /**
     * @param string $text Verbatim source text
     * @param array<string, mixed> $attributes Additional attributes
     */
    public function __construct(string $text, array $attributes = []) {
        $this->attributes = $attributes;
        $this->text = $text;
    }

    /**
     * Creates the appropriate leaf node for a token.
     *
     * @param LexerToken $token Token to convert
     * @param int $tokenPos Position of the token in the token array
     */
    public static function fromToken(LexerToken $token, int $tokenPos): self {
        $attributes = [
            'startLine' => $token->line,
            'startTokenPos' => $tokenPos,
            'startFilePos' => $token->pos,
            'endLine' => $token->getEndLine(),
            'endTokenPos' => $tokenPos,
            'endFilePos' => $token->getEndPos() - 1,
        ];
        $id = $token->id;
        if ($id === \T_WHITESPACE) {
            return new Whitespace($token->text, $attributes);
        }
        if ($id === \T_COMMENT || $id === \T_DOC_COMMENT) {
            return new Comment($token->text, $attributes);
        }
        return new Token($token->text, $attributes);
    }

    /**
     * Whether this leaf is trivia, i.e. whitespace or a comment, as opposed to a syntactic token.
     */
    public function isTrivia(): bool {
        return false;
    }

    public function getText(): string {
        return $this->text;
    }

    public function __toString(): string {
        return $this->text;
    }

    public function getSubNodeNames(): array {
        return ['text'];
    }
}
