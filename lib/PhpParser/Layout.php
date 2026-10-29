<?php declare(strict_types=1);

namespace PhpParser;

use PhpParser\Layout\SubNodeRef;
use PhpParser\Node\Leaf;

/**
 * Lossless layout of a node.
 *
 * A layout records how the source text of a node is composed: it is the ordered sequence of
 * leaf nodes (whitespace, comments and syntactic tokens, see PhpParser\Node\Leaf) and references
 * to the sub-nodes of the node (see PhpParser\Layout\SubNodeRef). Concatenating the text of the
 * leaves with the (recursively laid out) text of the referenced sub-nodes reproduces the source
 * code of the node exactly, similar to how PSI trees work in IntelliJ.
 *
 * Layouts are attached to nodes in the "layout" attribute by the
 * PhpParser\NodeVisitor\LayoutAnnotatingVisitor and consumed by the
 * PhpParser\PrettyPrinter\Lossless printer. Leaf nodes located in-between the elements of array
 * sub-nodes (statement lists, argument lists, ...) are stored directly inside those arrays,
 * next to the functional nodes.
 *
 * Besides the children, a layout stores a snapshot of the sub-node values that are not
 * represented by nodes (strings, integers, flags, ...). The layout is only considered valid for
 * a node while the node still matches the snapshot (see matches()).
 */
final class Layout implements \JsonSerializable {
    /** Name of the node attribute in which layouts are stored. */
    public const ATTRIBUTE = 'layout';

    /** Node attributes that influence how a node is printed and are therefore part of the snapshot. */
    private const TRACKED_ATTRIBUTES = ['kind', 'rawValue', 'docLabel', 'docIndentation', 'hasLeadingNewline'];

    /** @var list<Leaf|SubNodeRef> Leaves and sub-node references in source order */
    public array $children;

    /** @var array<string, mixed> Snapshot of non-node sub-node values and tracked attributes */
    private array $snapshot;

    /**
     * @param list<Leaf|SubNodeRef> $children Leaves and sub-node references in source order
     * @param array<string, mixed> $snapshot Snapshot as created by takeSnapshot()
     */
    public function __construct(array $children, array $snapshot = []) {
        $this->children = $children;
        $this->snapshot = $snapshot;
    }

    /**
     * Returns the layout attached to a node, if any.
     */
    public static function of(Node $node): ?self {
        $layout = $node->getAttribute(self::ATTRIBUTE);
        return $layout instanceof self ? $layout : null;
    }

    /**
     * Takes a snapshot of all sub-node values that are not nodes or arrays of nodes.
     *
     * For scalar sub-nodes (and null) the value is stored. For array sub-nodes the non-node
     * entries of the array are stored by index (e.g. the skipped elements of a list()
     * destructuring). Node sub-nodes are not part of the snapshot, as they are covered by
     * sub-node references in the layout.
     *
     * @param Node $node Node to snapshot
     * @param string $prefix Key prefix, used for nodes whose sub-nodes are hoisted into the
     *                       layout of their parent (anonymous classes)
     * @return array<string, mixed> Snapshot
     */
    public static function takeSnapshot(Node $node, string $prefix = ''): array {
        $snapshot = [];
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->$name;
            if ($value instanceof Node) {
                continue;
            }
            if (\is_array($value)) {
                $nonNodes = [];
                foreach ($value as $i => $item) {
                    if (!$item instanceof Node) {
                        $nonNodes[$i] = $item;
                    }
                }
                $snapshot[$prefix . $name] = $nonNodes;
                continue;
            }
            $snapshot[$prefix . $name] = $value;
        }
        foreach (self::TRACKED_ATTRIBUTES as $attr) {
            if ($node->hasAttribute($attr)) {
                $snapshot[$prefix . '@' . $attr] = $node->getAttribute($attr);
            }
        }
        return $snapshot;
    }

    /**
     * Removes leaf nodes from an array of nodes, e.g. to iterate over the statements of a
     * statement list that has been interleaved with leaves.
     *
     * @template T of Node|null
     * @param array<T> $nodes Array of nodes, possibly containing leaves
     * @return list<T> Nodes without leaves
     */
    public static function withoutLeaves(array $nodes): array {
        $result = [];
        foreach ($nodes as $node) {
            if (!$node instanceof Leaf) {
                $result[] = $node;
            }
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function getSnapshot(): array {
        return $this->snapshot;
    }

    /**
     * Returns the leaves that are direct children of this layout (leaves stored inside array
     * sub-nodes are not included).
     *
     * @return list<Leaf>
     */
    public function getLeaves(): array {
        $leaves = [];
        foreach ($this->children as $child) {
            if ($child instanceof Leaf) {
                $leaves[] = $child;
            }
        }
        return $leaves;
    }

    /**
     * Returns the sub-node references of this layout.
     *
     * @return list<SubNodeRef>
     */
    public function getRefs(): array {
        $refs = [];
        foreach ($this->children as $child) {
            if ($child instanceof SubNodeRef) {
                $refs[] = $child;
            }
        }
        return $refs;
    }

    /**
     * Checks whether the layout still describes the given node.
     *
     * This is the case if all non-node sub-nodes (and tracked attributes) still have the value
     * they had when the layout was created, every node and non-empty array sub-node is
     * referenced from the layout, and every reference resolves to a node or an array.
     *
     * If this returns false, the node has been modified in a way the layout cannot express (for
     * example a modifier flag was changed or a previously missing return type was added) and the
     * node has to be printed by the regular pretty printer instead.
     */
    public function matches(Node $node): bool {
        $hoisted = $this->getHoistedSubNodes();

        // Compare snapshot.
        $current = self::takeSnapshot($node);
        foreach ($hoisted as $name => $_) {
            if (!\property_exists($node, $name) || !$node->$name instanceof Node) {
                return false;
            }
            $current += self::takeSnapshot($node->$name, $name . '.');
        }
        if (\count($current) !== \count($this->snapshot)) {
            return false;
        }
        foreach ($this->snapshot as $key => $value) {
            if (!\array_key_exists($key, $current) || $current[$key] !== $value) {
                return false;
            }
        }

        // All references must resolve.
        $referenced = [];
        foreach ($this->children as $child) {
            if (!$child instanceof SubNodeRef) {
                continue;
            }
            $value = $child->resolve($node);
            if ($value === null || $value instanceof Leaf) {
                return false;
            }
            $referenced[$child->getKey()] = true;
        }

        // All node and non-empty array sub-nodes must be referenced.
        return $this->allSubNodesReferenced($node, '', $hoisted, $referenced);
    }

    /**
     * @return array<string, true> Names of sub-nodes whose own sub-nodes are part of this layout
     */
    private function getHoistedSubNodes(): array {
        $hoisted = [];
        foreach ($this->children as $child) {
            if ($child instanceof SubNodeRef && \count($child->path) > 1) {
                $hoisted[$child->path[0]] = true;
            }
        }
        foreach ($this->snapshot as $key => $_) {
            $dotPos = \strpos($key, '.');
            if ($dotPos !== false) {
                $hoisted[\substr($key, 0, $dotPos)] = true;
            }
        }
        return $hoisted;
    }

    /**
     * @param array<string, true> $hoisted
     * @param array<string, true> $referenced
     */
    private function allSubNodesReferenced(Node $node, string $prefix, array $hoisted, array $referenced): bool {
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->$name;
            $key = $prefix . $name;
            if ($value instanceof Node) {
                if ($value instanceof Leaf) {
                    return false;
                }
                if ($prefix === '' && isset($hoisted[$name])) {
                    if (!$this->allSubNodesReferenced($value, $name . '.', [], $referenced)) {
                        return false;
                    }
                    continue;
                }
                if (!isset($referenced[$key])) {
                    return false;
                }
            } elseif (\is_array($value)) {
                if (isset($referenced[$key])) {
                    continue;
                }
                foreach ($value as $item) {
                    if ($item instanceof Node && !$item instanceof Leaf) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    public function __clone() {
        foreach ($this->children as $i => $child) {
            if ($child instanceof Leaf) {
                $this->children[$i] = clone $child;
            }
        }
    }

    /** @return array{nodeType: string, children: list<Leaf|SubNodeRef>, snapshot: array<string, mixed>} */
    public function jsonSerialize(): array {
        return [
            'nodeType' => 'Layout',
            'children' => $this->children,
            'snapshot' => $this->snapshot,
        ];
    }
}
