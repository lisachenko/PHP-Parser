Lossless layout (whitespace nodes)
==================================

By default, the AST produced by PHP-Parser is *abstract*: it does not contain whitespace,
comments (other than through the `comments` attribute) or syntactic tokens such as keywords,
parentheses and semicolons. The pretty printer regenerates all of this from scratch, and the
[formatting-preserving pretty printer](Pretty_printing.markdown#formatting-preserving-pretty-printing)
needs the original token stream to reproduce the original formatting.

The lossless layout feature provides an alternative, modelled after the PSI trees used by
IntelliJ: whitespace, comments and tokens are stored in the tree itself, right next to the
"functional" nodes, as **leaf nodes**. The source code of any node can then be reconstructed
exactly from the tree alone. Modifying the formatting is done by modifying the tree, not by
post-processing the printer output.

Enabling lossless layouts
-------------------------

Layouts are attached to a freshly parsed AST by the `LayoutAnnotatingVisitor`, which needs the
token array of the parse:

```php
<?php
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\LayoutAnnotatingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Lossless;

$parser = (new ParserFactory())->createForNewestSupportedVersion();
$stmts = $parser->parse($code);

$traverser = new NodeTraverser(new LayoutAnnotatingVisitor($parser->getTokens()));
$stmts = $traverser->traverse($stmts);
// Or, equivalently:
$stmts = LayoutAnnotatingVisitor::annotate($stmts, $parser->getTokens());

$printer = new Lossless();
assert($printer->prettyPrintFile($stmts) === $code);
```

Note that the traverser returns a *new* top-level array: it contains the leaves located
before the first and after the last statement (such as the `<?php` opening tag and the
trailing newline). The visitor relies on the token position attributes of the nodes and must
run on the unmodified AST, before any other modifications are made.

Structure
---------

Three kinds of leaf nodes exist, all extending `PhpParser\Node\Leaf` and exposing their verbatim
source text through the `text` property:

 * `Node\Leaf\Whitespace`: Whitespace between two tokens. `hasNewline()` tells whether it
   contains a line break.
 * `Node\Leaf\Comment`: A comment, including its delimiters. `isDocComment()` tells whether it
   is a doc comment, and `toComment()` converts it into the `PhpParser\Comment` representation
   used by the `comments` attribute. (The `comments` attribute is still populated as usual, the
   leaf is the representation of the comment that takes part in printing.)
 * `Node\Leaf\Token`: Any other token that is not represented by a node of its own: keywords,
   operators, punctuation, PHP tags, the raw text of literals, etc.

Whitespace and comments are *trivia* (`Leaf::isTrivia()`), tokens are not. Leaf nodes have the
usual position attributes (`startLine`, `startFilePos`, `startTokenPos`, ...).

Leaves are stored in two places:

 1. **In the `layout` attribute** of each node, as a `PhpParser\Layout` object. Its `children`
    property is the ordered list of the leaves of the node and of references to its sub-nodes
    (`PhpParser\Layout\SubNodeRef`), in source order. For example, `if ($a) {}` has the layout
    `Token(if) Whitespace( ) Token(() SubNodeRef(cond) Token()) Whitespace( ) Token({) Token(})`.
    Sub-node references are resolved by name, so replacing `$if->cond` with a different node
    does not invalidate the layout.
 2. **Inside array sub-nodes** (statement lists, argument lists, array items, ...), in-between
    the elements. `foo($a, $b)` has the argument list `[Arg, Token(,), Whitespace( ), Arg]`.
    The layout of the parent references the array as a whole. Leaves located before the first
    or after the last element belong to the layout of the parent, except for the top-level
    statement list, which has no parent.

A `Layout` additionally stores a snapshot of all sub-node values that are *not* nodes (strings,
integers, flags, ...) and of the formatting-relevant attributes (`kind`, `rawValue`,
`docLabel`, `docIndentation`, `hasLeadingNewline`). `Layout::matches($node)` checks whether the
layout still describes the node, see below.

The constructor arguments of an anonymous class are syntactically located inside the class
declaration, so in this one case the layout of the `new` expression references the sub-nodes of
the class through paths like `class.stmts`, and the `Stmt\Class_` node has no layout of its own.

Printing
--------

`PhpParser\PrettyPrinter\Lossless` extends the standard pretty printer. Nodes carrying a valid
layout are printed by concatenating their leaves and sub-nodes, which reproduces their source
text exactly. All other nodes are printed by the standard pretty printer, with the same
options (`indent`, `newline`, `phpVersion`, ...). Formatting is therefore only lost *locally*:
when a node falls back to the pretty printer, its sub-nodes still use their own layouts, and the
indentation of multi-line fallback output follows the surrounding code.

Before falling back, the printer tries to *repair* the layout, i.e. to adjust the leaves to the
modification, and stores the repaired layout on the node:

 * Modifier changes (`flags` of classes, methods, properties, constants, parameters and
   property hooks, `static` of closures) replace the modifier tokens.
 * A sub-node that was `null` and is now a node (e.g. a return type, a default value, an
   `extends` clause) is inserted together with the tokens it requires, and a sub-node that
   became `null` is removed together with its tokens.
 * Elements added to a previously empty list (e.g. parameters of a function without parameters,
   arguments of `new Foo`) are inserted together with the required parentheses or keywords.

A node falls back to the pretty printer when:

 * It has no layout at all (newly created nodes, or nodes the parser created without position
   information).
 * A non-node sub-node changed in a way that cannot be repaired, e.g. the `name` of a variable
   or the `value` of a literal.
 * A tracked attribute changed, e.g. the `kind` of an integer literal.
 * A sub-node was added, removed or a list was filled for which no repair information exists.

When a node is replaced by, or moved to a position with, a node that is not the original node
at that position, the printer takes care of operator precedence (adding parentheses as needed)
and of braces in `$obj->{$name}` and `"{$expr}"`. When a node is printed at a different
indentation than in the original source (because it was moved, or because its parent falls back
to the pretty printer), the line breaks inside the node are re-indented accordingly.

When nodes are inserted into an array without leaves around them, a default separator is
inserted: a comma for argument and item lists (followed by a line break if the list is formatted
with one element per line), a line break with the current indentation for statement lists. The
comments of such nodes (the `comments` attribute) are printed in front of them, unless they are
already present as comment leaves.

Modifying the tree
------------------

All formatting is manipulated through the tree:

```php
<?php
use PhpParser\Layout;
use PhpParser\Node;
use PhpParser\Node\Leaf;

// Change whitespace: put the opening brace of a function onto the same line.
foreach (Layout::of($function)->children as $child) {
    if ($child instanceof Leaf\Whitespace && $child->hasNewline()) {
        $child->text = ' ';
        break;
    }
}

// Insert a statement including its indentation.
array_splice($function->stmts, 0, 0, [
    new Node\Stmt\Expression(new Node\Expr\FuncCall(new Node\Name('bar'))),
    new Leaf\Whitespace("\n    "),
]);

// Remove the statement at index 2 together with the whitespace before it.
array_splice($function->stmts, 1, 2);

// Edit or add a comment.
$stmts[1]->text = '// New comment';
```

As leaves are nodes, they are visited by the node traverser like any other node, which allows
visitors to inspect and modify formatting. `Layout::withoutLeaves($array)` returns the
functional nodes of an array without the leaves, which is helpful when indexing into statement
lists.

Transferring layouts to a modified copy of the AST
--------------------------------------------------

The formatting-preserving printing mode (`printFormatPreserving()`, see the
[pretty printing documentation](Pretty_printing.markdown#formatting-preserving-pretty-printing))
uses layouts as well. It operates on a cloned AST whose nodes refer to the original nodes
through the `origNode` attribute: the `LayoutTransferVisitor` annotates the original AST,
copies the layout of each original node to its clone and diffs each node array of the clone
against the original array, so that the leaves in front of unchanged elements are carried over.
Added, replaced and moved elements are left without leaves (and are marked as not being at
their original position), so that the printer inserts separators for them. This visitor can
also be used on its own:

```php
<?php
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\LayoutAnnotatingVisitor;
use PhpParser\NodeVisitor\LayoutTransferVisitor;

$origStmts = $parser->parse($code);
// Clone before annotating: the clone must not carry leaves of its own.
$stmts = (new NodeTraverser(new CloningVisitor()))->traverse($origStmts);
// ... modify $stmts without caring about leaves ...
$origStmts = LayoutAnnotatingVisitor::annotate($origStmts, $parser->getTokens());
$stmts = (new NodeTraverser(new LayoutTransferVisitor($origStmts)))->traverse($stmts);
```

(Cloning an already annotated AST clones the layouts and leaves instead, so no transfer is
required in that case; the leaves then have to be maintained when modifying the clone.)

Interaction with other components
---------------------------------

 * The standard pretty printer ignores leaf nodes, so an annotated AST can still be printed
   with it (the leaves are then simply not used).
 * The `NameResolver`, the `ConstExprEvaluator`, the `NodeDumper` (which dumps the `layout`
   attribute when `dumpOtherAttributes` is enabled), the JSON encoding/decoding and the
   `CloningVisitor` (which clones the leaves, so the original and the clone can be modified
   independently) support leaf nodes.
 * Code that iterates over node arrays and assumes that all elements are of a specific node
   type needs to skip `Leaf` instances.
 * Memory usage increases significantly (roughly 3x for large files), as every token becomes a
   node with position attributes.
