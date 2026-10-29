<?php declare(strict_types=1);

namespace PhpParser\PrettyPrinter;

use PhpParser\Layout;
use PhpParser\Layout\SubNodeRef;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Leaf;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/**
 * Pretty printer reproducing the original source code from lossless layouts.
 *
 * Nodes that carry a valid layout (see PhpParser\Layout and the LayoutAnnotatingVisitor) are
 * printed by concatenating their leaves and sub-nodes, which reproduces their source text
 * exactly, including all whitespace and comments. Nodes without a layout, or nodes that were
 * modified in a way their layout cannot express, are printed by the standard pretty printer.
 * Their sub-nodes still use their own layouts where available, so formatting is only lost
 * locally.
 *
 * As the layout is part of the AST, source code transformations are performed by modifying the
 * tree, including the leaf nodes: changing the text of a whitespace leaf changes the
 * formatting, inserting a statement next to a whitespace leaf into a statement list controls
 * the indentation of the new statement, and so on. No original token stream is required.
 */
class Lossless extends Standard {
    public function prettyPrintFile(array $stmts): string {
        if (!$this->containsLeaves($stmts)) {
            return parent::prettyPrintFile($stmts);
        }

        $this->resetState();
        $this->preprocessNodes($stmts);
        return $this->handleMagicTokens($this->pLayoutArray(null, 'stmts', $stmts, ''));
    }

    public function prettyPrint(array $stmts): string {
        if (!$this->containsLeaves($stmts)) {
            return parent::prettyPrint($stmts);
        }

        $this->resetState();
        $this->preprocessNodes($stmts);
        $result = $this->pLayoutArray(null, 'stmts', $stmts, '');

        // Strip the opening tag, which is part of the top-level leaves.
        $first = \reset($stmts);
        if ($first instanceof Leaf\Token && \strncmp($first->text, '<?', 2) === 0
            && \strncmp($result, $first->text, \strlen($first->text)) === 0
        ) {
            $result = \substr($result, \strlen($first->text));
        }
        return \ltrim($this->handleMagicTokens($result));
    }

    protected function p(
        Node $node, int $precedence = self::MAX_PRECEDENCE, int $lhsPrecedence = self::MAX_PRECEDENCE,
        bool $parentFormatPreserved = false
    ): string {
        if ($node instanceof Leaf) {
            return $node->text;
        }

        if ($this->origTokens === null) {
            $layout = Layout::of($node);
            if ($layout !== null && $layout->matches($node)) {
                return $this->pLayout($node, $layout, $precedence, $lhsPrecedence);
            }
        }

        return parent::p($node, $precedence, $lhsPrecedence, $parentFormatPreserved);
    }

    /**
     * Prints a node from its (valid) layout.
     */
    protected function pLayout(Node $node, Layout $layout, int $precedence, int $lhsPrecedence): string {
        $result = '';
        $children = $layout->children;
        foreach ($children as $i => $child) {
            if ($child instanceof Leaf) {
                $result .= $child->text;
                continue;
            }
            if (!$child instanceof SubNodeRef) {
                throw new \LogicException('Layout children must be leaves or sub-node references');
            }

            $value = $child->resolve($node);
            $owner = $child->resolveOwner($node);
            if ($value instanceof Node) {
                $result .= $this->pLayoutChild($owner, $child->getName(), $value, $children, $i, $result);
            } elseif (\is_array($value)) {
                $result .= $this->pLayoutArray($owner, $child->getName(), $value, $result);
            }
        }

        return $this->pLayoutParens($node, $result, $precedence, $lhsPrecedence);
    }

    /**
     * Prints an array sub-node, including the leaves stored in-between its elements.
     *
     * @param Node|null $owner Node owning the array (null for the top level)
     * @param string $name Name of the array sub-node
     * @param array<Node|null> $items Array elements, interleaved with leaves
     * @param string $resultSoFar Output of the parent printed so far (used to determine indentation)
     */
    protected function pLayoutArray(?Node $owner, string $name, array $items, string $resultSoFar): string {
        $items = \array_values($items);
        $result = '';
        $needsGlue = false;
        foreach ($items as $i => $item) {
            if ($item instanceof Leaf) {
                $result .= $item->text;
                $needsGlue = false;
                continue;
            }
            if (!$item instanceof Node) {
                continue;
            }

            if ($needsGlue && !$item instanceof Stmt\InlineHTML) {
                // Two nodes without anything in-between: a node was inserted without leaves.
                $result .= $this->pLayoutListGlue($owner, $name, $resultSoFar . $result);
            }
            $printed = $this->pLayoutChild($owner, $name, $item, $items, $i, $resultSoFar . $result);
            $result .= $printed;
            // Inline HTML is naturally adjacent to the surrounding statements (the PHP tags are
            // part of the statements), and zero-length nodes print nothing.
            $needsGlue = $printed !== '' && !$item instanceof Stmt\InlineHTML;
        }
        return $result;
    }

    /**
     * Prints a child node referenced from a layout (or contained in a laid out array).
     *
     * @param array<Leaf|SubNodeRef|Node|null> $siblings Children of the layout or array containing the child
     * @param int $index Index of the child within $siblings
     */
    protected function pLayoutChild(
        ?Node $owner, string $name, Node $child, array $siblings, int $index, string $resultSoFar
    ): string {
        $layout = Layout::of($child);
        if ($layout !== null && $layout->matches($child)) {
            // Original formatting is reproduced exactly, no fixups are necessary.
            return $this->pLayout($child, $layout, self::MAX_PRECEDENCE, self::MAX_PRECEDENCE);
        }

        if ($child instanceof Node\InterpolatedStringPart) {
            // String parts have no printer method of their own, their escaping depends on the
            // kind of the enclosing string.
            return $this->escapeString($child->value, $this->getInterpolatedStringQuote($owner));
        }

        // Fall back to the standard pretty printer for this child. Multi-line output is indented
        // relative to the line the child starts on.
        $origIndentLevel = $this->indentLevel;
        $this->setIndentLevel($this->getIndentationAtEnd($resultSoFar, $origIndentLevel));

        $fixup = null;
        if ($owner !== null) {
            $this->initializeFixupMap();
            $fixup = $this->fixupMap[\get_class($owner)][$name] ?? null;
        }
        if ($fixup === null) {
            $result = $this->p($child);
        } else {
            $result = $this->pLayoutFixup($fixup, $child, \get_class($owner), $siblings, $index);
        }

        $this->setIndentLevel($origIndentLevel);
        return $result;
    }

    /**
     * Adds parentheses around the verbatim text of an operator node if the context requires it.
     */
    protected function pLayoutParens(Node $node, string $text, int $precedence, int $lhsPrecedence): string {
        $class = \get_class($node);
        if (!isset($this->precedenceMap[$class])) {
            return $text;
        }

        $opPrecedence = $this->precedenceMap[$class][0];
        if ($node instanceof Expr\BinaryOp || $node instanceof Expr\Ternary || $node instanceof Expr\Instanceof_) {
            $needsParens = $opPrecedence >= $precedence;
        } else {
            $needsParens = $opPrecedence >= $lhsPrecedence;
        }
        return $needsParens ? '(' . $text . ')' : $text;
    }

    /**
     * Prints a replaced child node, adding parentheses or braces if required by its position.
     *
     * This mirrors PrettyPrinterAbstract::pFixup(), but determines the presence of parentheses
     * from the surrounding leaves rather than from the original token stream.
     *
     * @param array<Leaf|SubNodeRef|Node|null> $siblings
     */
    protected function pLayoutFixup(int $fixup, Node $subNode, string $parentClass, array $siblings, int $index): string {
        switch ($fixup) {
            case self::FIXUP_PREC_LEFT:
                if (!$this->haveLeavesAround($siblings, $index, '(', ')')) {
                    $precedence = $this->precedenceMap[$parentClass][1];
                    return $this->p($subNode, $precedence, $precedence);
                }
                break;
            case self::FIXUP_PREC_RIGHT:
                if (!$this->haveLeavesAround($siblings, $index, '(', ')')) {
                    $precedence = $this->precedenceMap[$parentClass][2];
                    return $this->p($subNode, $precedence, $precedence);
                }
                break;
            case self::FIXUP_PREC_UNARY:
                if (!$this->haveLeavesAround($siblings, $index, '(', ')')) {
                    $precedence = $this->precedenceMap[$parentClass][0];
                    return $this->p($subNode, $precedence, $precedence);
                }
                break;
            case self::FIXUP_CALL_LHS:
                if ($this->callLhsRequiresParens($subNode)
                    && !$this->haveLeavesAround($siblings, $index, '(', ')')
                ) {
                    return '(' . $this->p($subNode) . ')';
                }
                break;
            case self::FIXUP_DEREF_LHS:
                if ($this->dereferenceLhsRequiresParens($subNode)
                    && !$this->haveLeavesAround($siblings, $index, '(', ')')
                ) {
                    return '(' . $this->p($subNode) . ')';
                }
                break;
            case self::FIXUP_STATIC_DEREF_LHS:
                if ($this->staticDereferenceLhsRequiresParens($subNode)
                    && !$this->haveLeavesAround($siblings, $index, '(', ')')
                ) {
                    return '(' . $this->p($subNode) . ')';
                }
                break;
            case self::FIXUP_NEW:
                if ($this->newOperandRequiresParens($subNode)
                    && !$this->haveLeavesAround($siblings, $index, '(', ')')
                ) {
                    return '(' . $this->p($subNode) . ')';
                }
                break;
            case self::FIXUP_BRACED_NAME:
            case self::FIXUP_VAR_BRACED_NAME:
                if ($subNode instanceof Expr
                    && !$this->haveLeavesAround($siblings, $index, '{', '}')
                ) {
                    return ($fixup === self::FIXUP_VAR_BRACED_NAME ? '$' : '')
                        . '{' . $this->p($subNode) . '}';
                }
                break;
            case self::FIXUP_ENCAPSED:
                if (!$subNode instanceof Node\InterpolatedStringPart
                    && !$this->haveLeavesAround($siblings, $index, '{', '}')
                ) {
                    return '{' . $this->p($subNode) . '}';
                }
                break;
            default:
                throw new \LogicException('Unknown fixup type');
        }

        // Nothing special to do
        return $this->p($subNode);
    }

    /**
     * Returns the separator to insert between two array elements that have no leaves between
     * them (because an element was inserted without accompanying leaves).
     *
     * Lists whose elements are naturally adjacent (e.g. the parts of an interpolated string)
     * are not known to the list insertion map, and get no separator.
     */
    protected function pLayoutListGlue(?Node $owner, string $name, string $resultSoFar): string {
        if ($owner === null) {
            $insertStr = "\n";
        } else {
            $this->initializeListInsertionMap();
            $insertStr = $this->listInsertionMap[\get_class($owner) . '->' . $name] ?? '';
        }
        if ($insertStr === "\n") {
            $origIndentLevel = $this->indentLevel;
            $this->setIndentLevel($this->getIndentationAtEnd($resultSoFar, $origIndentLevel));
            $insertStr = $this->nl;
            $this->setIndentLevel($origIndentLevel);
        }
        return $insertStr;
    }

    /**
     * Determines the quote character used for escaping the parts of an interpolated string.
     */
    protected function getInterpolatedStringQuote(?Node $owner): ?string {
        if ($owner instanceof Expr\ShellExec) {
            return '`';
        }
        if ($owner instanceof Scalar\InterpolatedString
            && $owner->getAttribute('kind') === Scalar\String_::KIND_HEREDOC
        ) {
            return null;
        }
        return '"';
    }

    /**
     * Checks whether the child at the given index is directly surrounded by the given tokens,
     * ignoring whitespace and comments.
     *
     * @param array<Leaf|SubNodeRef|Node|null> $siblings
     */
    protected function haveLeavesAround(array $siblings, int $index, string $before, string $after): bool {
        $prev = null;
        for ($i = $index - 1; $i >= 0; $i--) {
            $sibling = $siblings[$i];
            if ($sibling instanceof Leaf && $sibling->isTrivia()) {
                continue;
            }
            $prev = $sibling;
            break;
        }
        if (!$prev instanceof Leaf\Token || $prev->text !== $before) {
            return false;
        }

        $next = null;
        for ($i = $index + 1, $n = \count($siblings); $i < $n; $i++) {
            $sibling = $siblings[$i];
            if ($sibling instanceof Leaf && $sibling->isTrivia()) {
                continue;
            }
            $next = $sibling;
            break;
        }
        return $next instanceof Leaf\Token && $next->text === $after;
    }

    /**
     * Determines the indentation of the last line of the given text, or the default if the text
     * does not contain a line break.
     */
    protected function getIndentationAtEnd(string $text, int $default): int {
        $nlPos = \strrpos($text, "\n");
        if ($nlPos === false) {
            return $default;
        }

        $indent = 0;
        for ($i = $nlPos + 1, $len = \strlen($text); $i < $len; $i++) {
            $char = $text[$i];
            if ($char === ' ') {
                $indent++;
            } elseif ($char === "\t") {
                $indent += $this->tabWidth;
            } else {
                break;
            }
        }
        return $indent;
    }

    /**
     * @param array<Node|null> $nodes
     */
    protected function containsLeaves(array $nodes): bool {
        foreach ($nodes as $node) {
            if ($node instanceof Leaf) {
                return true;
            }
        }
        return false;
    }
}
