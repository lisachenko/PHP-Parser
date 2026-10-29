<?php declare(strict_types=1);

namespace PhpParser\Layout;

use PhpParser\Node;

/**
 * Reference to a sub-node (or an array sub-node) from within a layout.
 *
 * The reference is resolved by name against the node owning the layout, so it stays valid when
 * the sub-node is replaced by a different node. For an array sub-node the reference stands for
 * the whole array, including the leaf nodes stored in-between its elements.
 *
 * The path usually consists of a single sub-node name. The only case where it is longer is the
 * anonymous class: the layout of a `new class(...) {...}` expression references the sub-nodes of
 * the class through the "class" sub-node (e.g. ["class", "stmts"]), as the constructor arguments
 * of the `new` expression are located inside the class declaration.
 */
final class SubNodeRef implements \JsonSerializable {
    /** @var list<string> Names of the sub-nodes to follow, starting at the node owning the layout */
    public array $path;

    public function __construct(string ...$path) {
        if (!$path) {
            throw new \LogicException('Sub-node reference must have a non-empty path');
        }
        $this->path = \array_values($path);
    }

    /** Name of the referenced sub-node (last element of the path). */
    public function getName(): string {
        return $this->path[\count($this->path) - 1];
    }

    /** String form of the path, e.g. "cond" or "class.stmts". */
    public function getKey(): string {
        return \implode('.', $this->path);
    }

    /**
     * Resolves the reference against the node owning the layout.
     *
     * @return Node|array<Node|null>|null Referenced value or null if it does not exist
     */
    public function resolve(Node $node) {
        $owner = $this->resolveOwner($node);
        if ($owner === null) {
            return null;
        }
        $name = $this->getName();
        if (!\property_exists($owner, $name)) {
            return null;
        }
        $value = $owner->$name;
        if ($value instanceof Node || \is_array($value)) {
            return $value;
        }
        return null;
    }

    /**
     * Resolves the node that directly owns the referenced sub-node.
     *
     * @return Node|null Owning node or null if the path cannot be followed
     */
    public function resolveOwner(Node $node): ?Node {
        $owner = $node;
        for ($i = 0, $n = \count($this->path) - 1; $i < $n; $i++) {
            $name = $this->path[$i];
            if (!\property_exists($owner, $name)) {
                return null;
            }
            $owner = $owner->$name;
            if (!$owner instanceof Node) {
                return null;
            }
        }
        return $owner;
    }

    /** @return array{nodeType: string, path: list<string>} */
    public function jsonSerialize(): array {
        return ['nodeType' => 'SubNodeRef', 'path' => $this->path];
    }
}
