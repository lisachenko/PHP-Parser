<?php declare(strict_types=1);

namespace PhpParser\PrettyPrinter;

use PhpParser\Node\Leaf;

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
 *
 * The same machinery is used by the format-preserving printing mode of the standard printer
 * (printFormatPreserving()), which additionally transfers the layouts of the original AST to a
 * cloned and modified AST.
 */
class Lossless extends Standard {
    public function __construct(array $options = []) {
        parent::__construct($options);
        $this->useLayouts = true;
    }

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
}
