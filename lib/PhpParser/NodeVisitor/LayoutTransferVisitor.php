<?php declare(strict_types=1);

namespace PhpParser\NodeVisitor;

use PhpParser\Internal\DiffElem;
use PhpParser\Internal\Differ;
use PhpParser\Layout;
use PhpParser\Layout\SubNodeRef;
use PhpParser\Node;
use PhpParser\Node\Leaf;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor transferring the lossless layouts of an original AST to a cloned and modified AST.
 *
 * The modified AST is expected to have been created by the CloningVisitor, so that its nodes
 * refer to the original nodes through the "origNode" attribute. The original AST must carry
 * layouts (see LayoutAnnotatingVisitor).
 *
 * Each node that has an original node receives the layout of the original node. Array
 * sub-nodes are diffed against the original arrays, and the leaves (separators, whitespace,
 * comments) that were located in front of the kept elements are inserted into the arrays of the
 * modified AST. Elements that were added have no leaves in front of them, so that the printer
 * inserts a default separator. The top-level statement list is handled in the same way, so the
 * array returned by the traverser contains the leaves surrounding the statements.
 *
 * Nodes whose arrays were modified in a way that cannot be expressed by the leaves (e.g.
 * removal of all elements) are left without a layout and are printed by the regular pretty
 * printer.
 */
class LayoutTransferVisitor extends NodeVisitorAbstract {
    /** @var Node[] Original top-level statements, interleaved with leaves */
    private array $origStmts;
    /** @var Differ<Node|null> */
    private Differ $differ;
    /** @var \SplObjectStorage<Node, null> Nodes that are no longer at their original position */
    private \SplObjectStorage $moved;

    /**
     * @param Node[] $origStmts Original top-level statements, as returned by the
     *                          LayoutAnnotatingVisitor (interleaved with leaves)
     */
    public function __construct(array $origStmts) {
        $this->origStmts = $origStmts;
        $this->moved = new \SplObjectStorage();
        $this->differ = new Differ(function ($old, $new): bool {
            if ($old instanceof Node && $new instanceof Node) {
                return $old === $new || $old === $new->getAttribute('origNode');
            }
            // Can happen for array destructuring
            return $old === null && $new === null;
        });
    }

    public function beforeTraverse(array $nodes) {
        $this->moved = new \SplObjectStorage();
        if ($this->containsLeaves($nodes)) {
            // Already laid out.
            return null;
        }
        return $this->mergeArray($nodes, $this->origStmts, null, 'stmts');
    }

    public function enterNode(Node $node) {
        if ($node instanceof Leaf) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }
        if (Layout::of($node) !== null) {
            return null;
        }
        $origNode = $node->getAttribute('origNode');
        if (!$origNode instanceof Node || $origNode instanceof Leaf) {
            return null;
        }

        $layout = Layout::of($origNode);
        $children = $layout !== null ? $layout->children : [];
        $childrenChanged = false;
        $keepLayout = true;
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->$name;
            if (!\is_array($value) || $this->containsLeaves($value)) {
                continue;
            }
            $origValue = \property_exists($origNode, $name) ? $origNode->$name : null;
            if (!\is_array($origValue)) {
                continue;
            }
            $merged = $this->mergeArray($value, $origValue, $origNode, $name, $children, $childrenChanged);
            if ($merged === null) {
                $keepLayout = false;
                continue;
            }
            $node->$name = $merged;
        }

        if ($layout !== null && $keepLayout) {
            if ($childrenChanged || $this->moved->contains($node)) {
                $newLayout = new Layout($children, $layout->getSnapshot(), $layout->indentation);
                if (!$this->moved->contains($node)) {
                    $newLayout->setParent($layout->getParent());
                }
                $layout = $newLayout;
            }
            $node->setAttribute(Layout::ATTRIBUTE, $layout);
        }
        return null;
    }

    /**
     * Interleaves a modified array with the leaves of the original array.
     *
     * @param array<Node|null> $nodes Modified array
     * @param array<Node|null> $origNodes Original array, interleaved with leaves
     * @param Node|null $origParent Original node owning the array (null for the top level)
     * @param string $name Name of the array sub-node
     * @param array<Leaf|SubNodeRef> $parentChildren Children of the layout of the parent, which hold
     *                                                the leaves in front of the first element
     * @param bool $parentChildrenChanged Set if $parentChildren were modified
     * @return array<Node|null>|null Merged array, or null if the formatting cannot be preserved
     */
    private function mergeArray(
        array $nodes, array $origNodes, ?Node $origParent, string $name,
        array &$parentChildren = [], bool &$parentChildrenChanged = false
    ): ?array {
        // Decompose the original array into elements and the leaves in front of them.
        $origElems = [];
        $leading = [];
        $leaves = [];
        foreach ($origNodes as $item) {
            if ($item instanceof Leaf) {
                $leaves[] = $item;
                continue;
            }
            $origElems[] = $item;
            $leading[] = $leaves;
            $leaves = [];
        }
        $trailing = $leaves;
        $nodes = \array_values($nodes);

        $isStmtList = $name === 'stmts';
        if ($isStmtList && $origParent !== null && \count($origElems) === 1 && \count($nodes) !== 1
            && !$this->haveBracesAroundList($origParent, $name)
        ) {
            // This was a single statement without braces, but either additional statements
            // have been added, or the single statement has been removed. This requires the
            // addition of braces. For now fall back.
            // TODO: Try to preserve formatting
            return null;
        }

        // The leaves in front of the first element are located in the layout of the parent.
        // Determine the comments among them, which are considered part of the first element.
        $parentCommentStart = null;
        $parentCommentEnd = null;
        if ($origElems && $origParent !== null) {
            foreach ($parentChildren as $i => $child) {
                if ($child instanceof SubNodeRef && $child->getKey() === $name) {
                    $parentCommentEnd = $i;
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $leaf = $parentChildren[$j];
                        if (!$leaf instanceof Leaf || !$leaf->isTrivia()) {
                            break;
                        }
                        if ($leaf instanceof Leaf\Comment) {
                            $parentCommentStart = $j;
                        }
                    }
                    break;
                }
            }
        }

        $diff = $this->differ->diffWithReplacements($origElems, $nodes);

        $result = [];
        $delayedAdd = [];
        $beforeFirstKeepOrReplace = true;
        $skipRemovedNode = false;
        $origIdx = 0;
        foreach ($diff as $i => $diffElem) {
            $diffType = $diffElem->type;
            if ($diffType === DiffElem::TYPE_KEEP || $diffType === DiffElem::TYPE_REPLACE) {
                $beforeFirstKeepOrReplace = false;
                $origItem = $diffElem->old;
                $newItem = $diffElem->new;
                $lead = $leading[$origIdx++];

                if ($origItem === null || $newItem === null) {
                    // We can only handle the case where both are null
                    if ($origItem !== $newItem) {
                        return null;
                    }
                    foreach ($lead as $leaf) {
                        $result[] = $leaf;
                    }
                    $result[] = null;
                    continue;
                }
                if (!$newItem instanceof Node || !$origItem instanceof Node) {
                    return null;
                }
                if ($diffType === DiffElem::TYPE_REPLACE) {
                    $this->moved->attach($newItem);
                }

                // Comments may be assigned to multiple nodes if they start at the same position.
                // Make sure we don't try to print them multiple times.
                [$beforeComments, $commentLeaves] = $this->splitAtComments($lead);
                $isFirst = $origIdx === 1;
                if ($isFirst && $parentCommentStart !== null) {
                    $commentLeaves = \array_slice($parentChildren, $parentCommentStart, $parentCommentEnd - $parentCommentStart);
                    if ($delayedAdd || $newItem->getComments() !== $origItem->getComments()) {
                        // The comments move into the array (after the inserted elements) or are
                        // replaced, remove them from the parent.
                        \array_splice($parentChildren, $parentCommentStart, $parentCommentEnd - $parentCommentStart);
                        $parentChildrenChanged = true;
                    } else {
                        $commentLeaves = [];
                    }
                }
                if ($skipRemovedNode) {
                    // The leaves in front of the removed node were kept, drop those in front of
                    // this node instead.
                    if ($isStmtList && $this->containsTag($beforeComments)) {
                        // We'd remove an opening/closing PHP tag.
                        // TODO: Preserve formatting.
                        return null;
                    }
                    $beforeComments = [];
                    $skipRemovedNode = false;
                }
                foreach ($beforeComments as $leaf) {
                    $result[] = $leaf;
                }
                foreach ($delayedAdd as $delayedAddNode) {
                    $result[] = $delayedAddNode;
                }
                $delayedAdd = [];

                if ($newItem->getComments() !== $origItem->getComments()) {
                    $commentLeaves = $this->commentsToLeaves($newItem->getComments(), Layout::of($origItem));
                }
                foreach ($commentLeaves as $leaf) {
                    $result[] = $leaf;
                }
                $result[] = $newItem;
            } elseif ($diffType === DiffElem::TYPE_ADD) {
                $newItem = $diffElem->new;
                if (!$newItem instanceof Node) {
                    // We only support list insertion of nodes.
                    return null;
                }
                $this->moved->attach($newItem);
                if ($beforeFirstKeepOrReplace) {
                    // Will be inserted at the next "replace" or "keep" element
                    $delayedAdd[] = $newItem;
                    continue;
                }
                $result[] = $newItem;
            } elseif ($diffType === DiffElem::TYPE_REMOVE) {
                $origItem = $diffElem->old;
                $lead = $leading[$origIdx++];
                if (!$origItem instanceof Node) {
                    // We only support removal for nodes
                    return null;
                }
                if ($i === 0) {
                    // If we're removing from the start, keep the leaves before the node (but not
                    // its comments) and drop those after it, instead of the other way around.
                    [$beforeComments] = $this->splitAtComments($lead);
                    foreach ($beforeComments as $leaf) {
                        $result[] = $leaf;
                    }
                    if ($parentCommentStart !== null) {
                        \array_splice($parentChildren, $parentCommentStart, $parentCommentEnd - $parentCommentStart);
                        $parentChildrenChanged = true;
                    }
                    $skipRemovedNode = true;
                } elseif ($isStmtList && $this->containsTag($lead)) {
                    // We'd remove an opening/closing PHP tag.
                    // TODO: Preserve formatting.
                    return null;
                }
            } else {
                throw new \Exception("Shouldn't happen");
            }
        }

        if ($skipRemovedNode) {
            // TODO: Support removing single node.
            return null;
        }

        if (!empty($delayedAdd)) {
            if ($origElems) {
                // Can only happen if all original nodes were removed.
                return null;
            }
            // Insertion into an empty list: The printer adds the required tokens to the layout.
            return $nodes;
        }

        foreach ($trailing as $leaf) {
            $result[] = $leaf;
        }
        return $result;
    }

    /**
     * Whether the array sub-node is enclosed in braces in the layout of its parent.
     */
    private function haveBracesAroundList(Node $parent, string $name): bool {
        $layout = Layout::of($parent);
        if ($layout === null) {
            return false;
        }
        $children = $layout->children;
        foreach ($children as $i => $child) {
            if (!$child instanceof SubNodeRef || $child->getKey() !== $name) {
                continue;
            }
            for ($j = $i - 1; $j >= 0; $j--) {
                $sibling = $children[$j];
                if ($sibling instanceof Leaf && $sibling->isTrivia()) {
                    continue;
                }
                return $sibling instanceof Leaf\Token && $sibling->text === '{';
            }
        }
        return false;
    }

    /**
     * Splits leaves into the leaves before the first comment and the rest.
     *
     * @param list<Leaf> $leaves
     * @return array{list<Leaf>, list<Leaf>}
     */
    private function splitAtComments(array $leaves): array {
        foreach ($leaves as $i => $leaf) {
            if ($leaf instanceof Leaf\Comment) {
                return [\array_slice($leaves, 0, $i), \array_slice($leaves, $i)];
            }
        }
        return [$leaves, []];
    }

    /**
     * Creates leaves for the (changed) comments of a node, indented like the original node.
     *
     * @param \PhpParser\Comment[] $comments
     * @return list<Leaf>
     */
    private function commentsToLeaves(array $comments, ?Layout $origLayout): array {
        $nl = "\n" . ($origLayout !== null ? $origLayout->indentation : '');
        $leaves = [];
        foreach ($comments as $comment) {
            $leaves[] = new Leaf\Comment(\str_replace("\n", $nl, $comment->getReformattedText()));
            $leaves[] = new Leaf\Whitespace($nl);
        }
        return $leaves;
    }

    /**
     * @param list<Leaf> $leaves
     */
    private function containsTag(array $leaves): bool {
        foreach ($leaves as $leaf) {
            if ($leaf instanceof Leaf\Token
                && (\strncmp($leaf->text, '<?', 2) === 0 || \strncmp($leaf->text, '?>', 2) === 0)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<Node|null> $nodes
     */
    private function containsLeaves(array $nodes): bool {
        foreach ($nodes as $node) {
            if ($node instanceof Leaf) {
                return true;
            }
        }
        return false;
    }
}
