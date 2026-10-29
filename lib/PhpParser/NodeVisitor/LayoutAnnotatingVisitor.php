<?php declare(strict_types=1);

namespace PhpParser\NodeVisitor;

use PhpParser\Layout;
use PhpParser\Layout\SubNodeRef;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Leaf;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Token;

/**
 * Visitor attaching lossless layouts to all nodes of a freshly parsed AST.
 *
 * After traversal:
 *  * Every node carries a "layout" attribute (see PhpParser\Layout) listing the leaf nodes
 *    (whitespace, comments, tokens) and sub-node references that make up its source text.
 *  * Array sub-nodes (statement lists, argument lists, ...) contain leaf nodes in-between
 *    their elements, holding the separators and whitespace found between the elements.
 *  * The array of top-level statements returned by the traverser additionally contains the
 *    leaves before the first and after the last statement (opening tag, trailing newline, ...).
 *
 * The visitor requires the token array of the parse (Parser::getTokens()) and must run on the
 * unmodified AST produced by the parser, as it relies on the token position attributes.
 *
 * Nodes whose source text cannot be described by a layout (e.g. nodes without position
 * information) are left without a "layout" attribute and are printed by the regular pretty
 * printer when using the lossless printer.
 */
class LayoutAnnotatingVisitor extends NodeVisitorAbstract {
    /** @var Token[] Token array */
    private array $tokens;
    /** @var int Position of the last token that is not the sentinel token */
    private int $lastTokenPos;
    /** @var \SplObjectStorage<Node, null> Nodes whose sub-nodes were hoisted into the parent layout */
    private \SplObjectStorage $hoisted;
    /** @var \SplObjectStorage<Node, Node> Map from child nodes to the node owning them */
    private \SplObjectStorage $parents;
    /** @var string Source code, reconstructed from the tokens */
    private string $code;
    /** @var array<string, string> Cache of indentation strings, to share identical strings */
    private array $indentations = [];

    /**
     * @param Token[] $tokens Token array of the parse, as returned by Parser::getTokens()
     */
    public function __construct(array $tokens) {
        $this->tokens = $tokens;
        $numTokens = \count($tokens);
        $this->lastTokenPos = $numTokens - 1;
        if ($numTokens > 0 && $tokens[$numTokens - 1]->id === 0) {
            // Ignore the sentinel token.
            $this->lastTokenPos = $numTokens - 2;
        }
        $this->hoisted = new \SplObjectStorage();
        $this->parents = new \SplObjectStorage();
        $code = '';
        for ($i = 0; $i <= $this->lastTokenPos; $i++) {
            $code .= $tokens[$i]->text;
        }
        $this->code = $code;
    }

    /**
     * Convenience method annotating an AST with layouts.
     *
     * @param Node[] $stmts Statements returned by the parser
     * @param Token[] $tokens Tokens returned by Parser::getTokens()
     * @return Node[] Top-level statements interleaved with leaf nodes
     */
    public static function annotate(array $stmts, array $tokens): array {
        $traverser = new NodeTraverser(new self($tokens));
        return $traverser->traverse($stmts);
    }

    public function beforeTraverse(array $nodes) {
        $this->hoisted = new \SplObjectStorage();
        $this->parents = new \SplObjectStorage();
        return $this->buildTopLevel($nodes);
    }

    public function enterNode(Node $node) {
        if ($node instanceof Leaf) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }
        if ($node->hasAttribute(Layout::ATTRIBUTE) || $this->hoisted->contains($node)) {
            return null;
        }
        $layout = $this->buildLayout($node);
        if ($layout !== null) {
            $node->setAttribute(Layout::ATTRIBUTE, $layout);
        }
        return null;
    }

    public function afterTraverse(array $nodes) {
        $this->hoisted = new \SplObjectStorage();
        $this->parents = new \SplObjectStorage();
        return null;
    }

    /**
     * Interleaves the top-level statements with all leaves outside of them.
     *
     * @param Node[] $nodes
     * @return Node[]|null New list or null if the list cannot be laid out
     */
    private function buildTopLevel(array $nodes): ?array {
        $result = [];
        $pos = 0;
        foreach ($nodes as $node) {
            if (!$node instanceof Node || $node instanceof Leaf) {
                return null;
            }
            $start = $node->getStartTokenPos();
            $end = $node->getEndTokenPos();
            if ($start < $pos || $end < $start - 1 || $end > $this->lastTokenPos) {
                return null;
            }
            $this->appendLeaves($result, $pos, $start - 1);
            $result[] = $node;
            $pos = \max($pos, $end + 1);
        }
        $this->appendLeaves($result, $pos, $this->lastTokenPos);
        return $result;
    }

    private function buildLayout(Node $node): ?Layout {
        $start = $node->getStartTokenPos();
        $end = $node->getEndTokenPos();
        if ($start < 0 || $end < $start - 1 || $end > $this->lastTokenPos) {
            return null;
        }

        // The constructor arguments of an anonymous class are located inside the class
        // declaration, so the sub-nodes of the class are laid out as part of the `new`.
        $hoistedName = null;
        if ($node instanceof Expr\New_ && $node->class instanceof Stmt\Class_
            && !$node->class->hasAttribute(Layout::ATTRIBUTE)
        ) {
            $hoistedName = 'class';
        }

        $items = $this->collectChildren($node, [], $hoistedName);
        if ($items === null) {
            return null;
        }
        \usort($items, function (array $a, array $b): int {
            return [$a['start'], $a['ord']] <=> [$b['start'], $b['ord']];
        });

        $children = [];
        /** @var array<string, array<int, list<Leaf>>> Leaves to insert before array elements, by key and index */
        $arrayLeaves = [];
        /** @var array<string, int> Last seen index per array key */
        $lastIndex = [];
        $prevArrayKey = null;
        $pos = $start;
        foreach ($items as $item) {
            if ($item['start'] < $pos || $item['end'] > $end) {
                // Overlapping children or child outside of parent.
                return null;
            }
            $key = \implode('.', $item['path']);
            $leaves = [];
            $this->appendLeaves($leaves, $pos, $item['start'] - 1);
            if ($item['index'] !== null) {
                if (isset($lastIndex[$key])) {
                    // Elements of an array must be contiguous and in order.
                    if ($prevArrayKey !== $key || $item['index'] <= $lastIndex[$key]) {
                        return null;
                    }
                    $arrayLeaves[$key][$item['index']] = $leaves;
                } else {
                    foreach ($leaves as $leaf) {
                        $children[] = $leaf;
                    }
                    $children[] = new SubNodeRef(...$item['path']);
                    $arrayLeaves[$key] = [];
                }
                $lastIndex[$key] = $item['index'];
                $prevArrayKey = $key;
            } else {
                foreach ($leaves as $leaf) {
                    $children[] = $leaf;
                }
                $children[] = new SubNodeRef(...$item['path']);
                $prevArrayKey = null;
            }
            $pos = \max($pos, $item['end'] + 1);
        }
        $this->appendLeaves($children, $pos, $end);

        // Store leaves located between array elements inside the arrays.
        foreach ($arrayLeaves as $key => $leavesByIndex) {
            if (!$leavesByIndex) {
                continue;
            }
            $path = \explode('.', $key);
            $name = \array_pop($path);
            $owner = $node;
            foreach ($path as $part) {
                $owner = $owner->$part;
            }
            $owner->$name = $this->interleave($owner->$name, $leavesByIndex);
        }

        $snapshot = Layout::takeSnapshot($node);
        if ($hoistedName !== null) {
            $snapshot += Layout::takeSnapshot($node->$hoistedName, $hoistedName . '.');
            $this->hoisted->attach($node->$hoistedName);
        }

        // Remember which node owns each child, so that the children can record their parent.
        foreach ($items as $item) {
            $owner = $node;
            for ($i = 0, $n = \count($item['path']) - 1; $i < $n; $i++) {
                $owner = $owner->{$item['path'][$i]};
            }
            $this->parents[$item['node']] = $owner;
        }

        $layout = new Layout($children, $snapshot, $this->getIndentation($start));
        if ($this->parents->contains($node)) {
            $layout->setParent($this->parents[$node]);
        } else {
            $layout->markTopLevel();
        }
        return $layout;
    }

    /**
     * Collects the positioned child nodes of a node.
     *
     * @param list<string> $prefix Path prefix for hoisted sub-nodes
     * @return list<array{start: int, end: int, path: list<string>, index: int|null, ord: int, node: Node}>|null
     *         Children or null if a child lacks position information
     */
    private function collectChildren(Node $node, array $prefix, ?string $hoistedName): ?array {
        $items = [];
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->$name;
            if ($value instanceof Node) {
                if ($name === $hoistedName) {
                    $hoistedItems = $this->collectChildren($value, [$name], null);
                    if ($hoistedItems === null) {
                        return null;
                    }
                    foreach ($hoistedItems as $item) {
                        $items[] = $item;
                    }
                    continue;
                }
                $item = $this->createItem($value, \array_merge($prefix, [$name]), null, \count($items));
                if ($item === null) {
                    return null;
                }
                $items[] = $item;
            } elseif (\is_array($value)) {
                foreach ($value as $i => $elem) {
                    if (!$elem instanceof Node) {
                        continue;
                    }
                    if (!\is_int($i)) {
                        return null;
                    }
                    $item = $this->createItem($elem, \array_merge($prefix, [$name]), $i, \count($items));
                    if ($item === null) {
                        return null;
                    }
                    $items[] = $item;
                }
            }
        }
        return $items;
    }

    /**
     * @param list<string> $path
     * @return array{start: int, end: int, path: list<string>, index: int|null, ord: int, node: Node}|null
     */
    private function createItem(Node $node, array $path, ?int $index, int $ord): ?array {
        if ($node instanceof Leaf) {
            return null;
        }
        $start = $node->getStartTokenPos();
        $end = $node->getEndTokenPos();
        if ($start < 0 || $end < $start - 1) {
            return null;
        }
        return ['start' => $start, 'end' => $end, 'path' => $path, 'index' => $index, 'ord' => $ord, 'node' => $node];
    }

    /**
     * Determines the leading whitespace of the line on which the token at the given position
     * starts.
     */
    private function getIndentation(int $tokenPos): string {
        $pos = $tokenPos <= $this->lastTokenPos ? $this->tokens[$tokenPos]->pos : \strlen($this->code);
        $lineStart = $pos > 0 ? \strrpos($this->code, "\n", $pos - \strlen($this->code) - 1) : false;
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $end = $lineStart;
        while ($end < $pos && ($this->code[$end] === ' ' || $this->code[$end] === "\t")) {
            $end++;
        }
        $indentation = \substr($this->code, $lineStart, $end - $lineStart);
        return $this->indentations[$indentation] ??= $indentation;
    }

    /**
     * Inserts leaves before the array elements with the given indices.
     *
     * @param array<int, Node|null> $array
     * @param array<int, list<Leaf>> $leavesByIndex
     * @return list<Node|null>
     */
    private function interleave(array $array, array $leavesByIndex): array {
        $result = [];
        foreach ($array as $i => $elem) {
            if (isset($leavesByIndex[$i])) {
                foreach ($leavesByIndex[$i] as $leaf) {
                    $result[] = $leaf;
                }
            }
            $result[] = $elem;
        }
        return $result;
    }

    /**
     * Appends leaves for the tokens in the given (inclusive) position range.
     *
     * @param array<Node|null> $target
     */
    private function appendLeaves(array &$target, int $from, int $to): void {
        $to = \min($to, $this->lastTokenPos);
        for ($i = $from; $i <= $to; $i++) {
            $target[] = Leaf::fromToken($this->tokens[$i], $i);
        }
    }
}
