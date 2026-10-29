<?php declare(strict_types=1);

namespace PhpParser\Internal;

use PhpParser\Layout\SubNodeRef;
use PhpParser\Node;

/**
 * Context for repairing the layout of a node: The node owning the layout, the node whose
 * sub-nodes are currently being adjusted (the same node, or the hoisted class of an anonymous
 * class expression) and the path prefix of its sub-nodes.
 *
 * @internal
 */
final class LayoutRepairContext {
    /** @var Node Node owning the layout */
    public Node $node;
    /** @var Node Node whose sub-nodes are adjusted */
    public Node $owner;
    /** @var string Key prefix of the sub-nodes of the owner ("" or "class.") */
    public string $prefix;
    /** @var string Indentation of the line the node starts on */
    public string $indentation;

    public function __construct(Node $node, Node $owner, string $prefix, string $indentation) {
        $this->node = $node;
        $this->owner = $owner;
        $this->prefix = $prefix;
        $this->indentation = $indentation;
    }

    /**
     * Path of a sub-node of the owner, as used by SubNodeRef.
     *
     * @return list<string>
     */
    public function path(string $name): array {
        if ($this->prefix === '') {
            return [$name];
        }
        return \array_merge(\explode('.', \rtrim($this->prefix, '.')), [$name]);
    }
}
