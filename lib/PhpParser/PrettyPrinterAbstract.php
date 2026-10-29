<?php declare(strict_types=1);

namespace PhpParser;

use PhpParser\Layout;
use PhpParser\Layout\SubNodeRef;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Leaf;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\Node\UnionType;
use PhpParser\NodeVisitor\LayoutAnnotatingVisitor;
use PhpParser\NodeVisitor\LayoutTransferVisitor;

abstract class PrettyPrinterAbstract implements PrettyPrinter {
    protected const FIXUP_PREC_LEFT = 0; // LHS operand affected by precedence
    protected const FIXUP_PREC_RIGHT = 1; // RHS operand affected by precedence
    protected const FIXUP_PREC_UNARY = 2; // Only operand affected by precedence
    protected const FIXUP_CALL_LHS = 3; // LHS of call
    protected const FIXUP_DEREF_LHS = 4; // LHS of dereferencing operation
    protected const FIXUP_STATIC_DEREF_LHS = 5; // LHS of static dereferencing operation
    protected const FIXUP_BRACED_NAME  = 6; // Name operand that may require bracing
    protected const FIXUP_VAR_BRACED_NAME = 7; // Name operand that may require ${} bracing
    protected const FIXUP_ENCAPSED = 8; // Encapsed string part
    protected const FIXUP_NEW = 9; // New/instanceof operand

    protected const MAX_PRECEDENCE = 1000;

    /** Pseudo class name used in the insertion/removal maps for the sub-nodes of anonymous classes. */
    protected const ANON_CLASS = 'AnonymousClass';

    /** @var array<class-string, array{int, int, int}> */
    protected array $precedenceMap = [
        // [precedence, precedenceLHS, precedenceRHS]
        // Where the latter two are the precedences to use for the LHS and RHS of a binary operator,
        // where 1 is added to one of the sides depending on associativity. This information is not
        // used for unary operators and set to -1.
        Expr\Clone_::class             => [-10,   0,   1],
        BinaryOp\Pow::class            => [  0,   0,   1],
        Expr\BitwiseNot::class         => [ 10,  -1,  -1],
        Expr\UnaryPlus::class          => [ 10,  -1,  -1],
        Expr\UnaryMinus::class         => [ 10,  -1,  -1],
        Cast\Int_::class               => [ 10,  -1,  -1],
        Cast\Double::class             => [ 10,  -1,  -1],
        Cast\String_::class            => [ 10,  -1,  -1],
        Cast\Array_::class             => [ 10,  -1,  -1],
        Cast\Object_::class            => [ 10,  -1,  -1],
        Cast\Bool_::class              => [ 10,  -1,  -1],
        Cast\Unset_::class             => [ 10,  -1,  -1],
        Expr\ErrorSuppress::class      => [ 10,  -1,  -1],
        Expr\Instanceof_::class        => [ 20,  -1,  -1],
        Expr\BooleanNot::class         => [ 30,  -1,  -1],
        BinaryOp\Mul::class            => [ 40,  41,  40],
        BinaryOp\Div::class            => [ 40,  41,  40],
        BinaryOp\Mod::class            => [ 40,  41,  40],
        BinaryOp\Plus::class           => [ 50,  51,  50],
        BinaryOp\Minus::class          => [ 50,  51,  50],
        // FIXME: This precedence is incorrect for PHP 8.
        BinaryOp\Concat::class         => [ 50,  51,  50],
        BinaryOp\ShiftLeft::class      => [ 60,  61,  60],
        BinaryOp\ShiftRight::class     => [ 60,  61,  60],
        BinaryOp\Pipe::class           => [ 65,  66,  65],
        BinaryOp\Smaller::class        => [ 70,  70,  70],
        BinaryOp\SmallerOrEqual::class => [ 70,  70,  70],
        BinaryOp\Greater::class        => [ 70,  70,  70],
        BinaryOp\GreaterOrEqual::class => [ 70,  70,  70],
        BinaryOp\Equal::class          => [ 80,  80,  80],
        BinaryOp\NotEqual::class       => [ 80,  80,  80],
        BinaryOp\Identical::class      => [ 80,  80,  80],
        BinaryOp\NotIdentical::class   => [ 80,  80,  80],
        BinaryOp\Spaceship::class      => [ 80,  80,  80],
        BinaryOp\BitwiseAnd::class     => [ 90,  91,  90],
        BinaryOp\BitwiseXor::class     => [100, 101, 100],
        BinaryOp\BitwiseOr::class      => [110, 111, 110],
        BinaryOp\BooleanAnd::class     => [120, 121, 120],
        BinaryOp\BooleanOr::class      => [130, 131, 130],
        BinaryOp\Coalesce::class       => [140, 140, 141],
        Expr\Ternary::class            => [150, 150, 150],
        Expr\Assign::class             => [160,  -1,  -1],
        Expr\AssignRef::class          => [160,  -1,  -1],
        AssignOp\Plus::class           => [160,  -1,  -1],
        AssignOp\Minus::class          => [160,  -1,  -1],
        AssignOp\Mul::class            => [160,  -1,  -1],
        AssignOp\Div::class            => [160,  -1,  -1],
        AssignOp\Concat::class         => [160,  -1,  -1],
        AssignOp\Mod::class            => [160,  -1,  -1],
        AssignOp\BitwiseAnd::class     => [160,  -1,  -1],
        AssignOp\BitwiseOr::class      => [160,  -1,  -1],
        AssignOp\BitwiseXor::class     => [160,  -1,  -1],
        AssignOp\ShiftLeft::class      => [160,  -1,  -1],
        AssignOp\ShiftRight::class     => [160,  -1,  -1],
        AssignOp\Pow::class            => [160,  -1,  -1],
        AssignOp\Coalesce::class       => [160,  -1,  -1],
        Expr\YieldFrom::class          => [170,  -1,  -1],
        Expr\Yield_::class             => [175,  -1,  -1],
        Expr\Print_::class             => [180,  -1,  -1],
        BinaryOp\LogicalAnd::class     => [190, 191, 190],
        BinaryOp\LogicalXor::class     => [200, 201, 200],
        BinaryOp\LogicalOr::class      => [210, 211, 210],
        Expr\Include_::class           => [220,  -1,  -1],
        Expr\ArrowFunction::class      => [230,  -1,  -1],
        Expr\Throw_::class             => [240,  -1,  -1],
        Expr\Cast\Void_::class         => [250,  -1,  -1],
    ];

    /** @var int Current indentation level. */
    protected int $indentLevel;
    /** @var string String for single level of indentation */
    private string $indent;
    /** @var int Width in spaces to indent by. */
    private int $indentWidth;
    /** @var bool Whether to use tab indentation. */
    private bool $useTabs;
    /** @var int Width in spaces of one tab. */
    protected int $tabWidth = 4;

    /** @var string Newline style. Does not include current indentation. */
    protected string $newline;
    /** @var string Newline including current indentation. */
    protected string $nl;
    /** @var string|null Token placed at end of doc string to ensure it is followed by a newline.
     *                   Null if flexible doc strings are used. */
    protected ?string $docStringEndToken;
    /** @var bool Whether semicolon namespaces can be used (i.e. no global namespace is used) */
    protected bool $canUseSemicolonNamespaces;
    /** @var bool Whether to use short array syntax if the node specifies no preference */
    protected bool $shortArraySyntax;
    /** @var PhpVersion PHP version to target */
    protected PhpVersion $phpVersion;

    /** @var bool Whether nodes are printed from their lossless layouts, if available */
    protected bool $useLayouts = false;
    /** @var array<string, bool> Map determining whether a certain character is a label character */
    protected array $labelCharMap;
    /**
     * @var array<string, array<string, int>> Map from token classes and subnode names to FIXUP_* constants.
     *                                        This is used during format-preserving prints to place additional parens/braces if necessary.
     */
    protected array $fixupMap;
    /**
     * @var array<string, array{left?: int|string, right?: int|string}> Map from "{$node->getType()}->{$subNode}"
     *                                                                  to ['left' => $l, 'right' => $r], where $l and $r specify the token type that needs to be stripped
     *                                                                  when removing this node.
     */
    protected array $removalMap;
    /**
     * @var array<string, array{int|string|null, bool, string|null, string|null}> Map from
     *                                                                            "{$node->getType()}->{$subNode}" to [$find, $beforeToken, $extraLeft, $extraRight].
     *                                                                            $find is an optional token after which the insertion occurs. $extraLeft/Right
     *                                                                            are optionally added before/after the main insertions.
     */
    protected array $insertionMap;
    /**
     * @var array<string, string> Map From "{$class}->{$subNode}" to string that should be inserted
     *                            between elements of this list subnode.
     */
    protected array $listInsertionMap;

    /**
     * @var array<string, array{int|string|null, string, string}>
     */
    protected array $emptyListInsertionMap;
    /** @var array<string, array{string, int, int}>
     *       Map from "{$class}->{$subNode}" to [$printFn, $skipToken, $findToken] where $printFn is the function to
     *       print the modifiers, $skipToken is the token to skip at the start and $findToken is the token before which
     *       the modifiers should be reprinted. */
    protected array $modifierChangeMap;

    /**
     * Creates a pretty printer instance using the given options.
     *
     * Supported options:
     *  * PhpVersion $phpVersion: The PHP version to target (default to PHP 7.4). This option
     *                            controls compatibility of the generated code with older PHP
     *                            versions in cases where a simple stylistic choice exists (e.g.
     *                            array() vs []). It is safe to pretty-print an AST for a newer
     *                            PHP version while specifying an older target (but the result will
     *                            of course not be compatible with the older version in that case).
     *  * string $newline:        The newline style to use. Should be "\n" (default) or "\r\n".
     *  * string $indent:         The indentation to use. Should either be all spaces or a single
     *                            tab. Defaults to four spaces ("    ").
     *  * bool $shortArraySyntax: Whether to use [] instead of array() as the default array
     *                            syntax, if the node does not specify a format. Defaults to whether
     *                            the phpVersion support short array syntax.
     *
     * @param array{
     *     phpVersion?: PhpVersion, newline?: string, indent?: string, shortArraySyntax?: bool
     * } $options Dictionary of formatting options
     */
    public function __construct(array $options = []) {
        $this->phpVersion = $options['phpVersion'] ?? PhpVersion::fromComponents(7, 4);

        $this->newline = $options['newline'] ?? "\n";
        if ($this->newline !== "\n" && $this->newline != "\r\n") {
            throw new \LogicException('Option "newline" must be one of "\n" or "\r\n"');
        }

        $this->shortArraySyntax =
            $options['shortArraySyntax'] ?? $this->phpVersion->supportsShortArraySyntax();
        $this->docStringEndToken =
            $this->phpVersion->supportsFlexibleHeredoc() ? null : '_DOC_STRING_END_' . mt_rand();

        $this->indent = $indent = $options['indent'] ?? '    ';
        if ($indent === "\t") {
            $this->useTabs = true;
            $this->indentWidth = $this->tabWidth;
        } elseif ($indent === \str_repeat(' ', \strlen($indent))) {
            $this->useTabs = false;
            $this->indentWidth = \strlen($indent);
        } else {
            throw new \LogicException('Option "indent" must either be all spaces or a single tab');
        }
    }

    /**
     * Reset pretty printing state.
     */
    protected function resetState(): void {
        $this->indentLevel = 0;
        $this->nl = $this->newline;
    }

    /**
     * Set indentation level
     *
     * @param int $level Level in number of spaces
     */
    protected function setIndentLevel(int $level): void {
        $this->indentLevel = $level;
        if ($this->useTabs) {
            $tabs = \intdiv($level, $this->tabWidth);
            $spaces = $level % $this->tabWidth;
            $this->nl = $this->newline . \str_repeat("\t", $tabs) . \str_repeat(' ', $spaces);
        } else {
            $this->nl = $this->newline . \str_repeat(' ', $level);
        }
    }

    /**
     * Increase indentation level.
     */
    protected function indent(): void {
        $this->indentLevel += $this->indentWidth;
        $this->nl .= $this->indent;
    }

    /**
     * Decrease indentation level.
     */
    protected function outdent(): void {
        assert($this->indentLevel >= $this->indentWidth);
        $this->setIndentLevel($this->indentLevel - $this->indentWidth);
    }

    /**
     * Pretty prints an array of statements.
     *
     * @param Node[] $stmts Array of statements
     *
     * @return string Pretty printed statements
     */
    public function prettyPrint(array $stmts): string {
        $this->resetState();
        $this->preprocessNodes($stmts);

        return ltrim($this->handleMagicTokens($this->pStmts($stmts, false)));
    }

    /**
     * Pretty prints an expression.
     *
     * @param Expr $node Expression node
     *
     * @return string Pretty printed node
     */
    public function prettyPrintExpr(Expr $node): string {
        $this->resetState();
        return $this->handleMagicTokens($this->p($node));
    }

    /**
     * Pretty prints a file of statements (includes the opening <?php tag if it is required).
     *
     * @param Node[] $stmts Array of statements
     *
     * @return string Pretty printed statements
     */
    public function prettyPrintFile(array $stmts): string {
        $stmts = $this->withoutLeaves($stmts);
        if (!$stmts) {
            return "<?php" . $this->newline . $this->newline;
        }

        $p = "<?php" . $this->newline . $this->newline . $this->prettyPrint($stmts);

        if ($stmts[0] instanceof Stmt\InlineHTML) {
            $p = preg_replace('/^<\?php\s+\?>\r?\n?/', '', $p);
        }
        if ($stmts[count($stmts) - 1] instanceof Stmt\InlineHTML) {
            $p = preg_replace('/<\?php$/', '', rtrim($p));
        }

        return $p;
    }

    /**
     * Preprocesses the top-level nodes to initialize pretty printer state.
     *
     * @param Node[] $nodes Array of nodes
     */
    protected function preprocessNodes(array $nodes): void {
        /* We can use semicolon-namespaces unless there is a global namespace declaration */
        $this->canUseSemicolonNamespaces = true;
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Namespace_ && null === $node->name) {
                $this->canUseSemicolonNamespaces = false;
                break;
            }
        }
    }

    /**
     * Handles (and removes) doc-string-end tokens.
     */
    protected function handleMagicTokens(string $str): string {
        if ($this->docStringEndToken !== null) {
            // Replace doc-string-end tokens with nothing or a newline
            $str = str_replace(
                $this->docStringEndToken . ';' . $this->newline,
                ';' . $this->newline,
                $str);
            $str = str_replace($this->docStringEndToken, $this->newline, $str);
        }

        return $str;
    }

    /**
     * Pretty prints an array of nodes (statements) and indents them optionally.
     *
     * @param Node[] $nodes Array of nodes
     * @param bool $indent Whether to indent the printed nodes
     *
     * @return string Pretty printed statements
     */
    protected function pStmts(array $nodes, bool $indent = true): string {
        if ($indent) {
            $this->indent();
        }

        $result = '';
        foreach ($nodes as $node) {
            if ($node instanceof Leaf) {
                // Leaves of lossless layouts are only printed by the lossless printer.
                continue;
            }

            $comments = $node->getComments();
            if ($comments) {
                $result .= $this->nl . $this->pComments($comments);
                if ($node instanceof Stmt\Nop) {
                    continue;
                }
            }

            $result .= $this->nl . $this->p($node);
        }

        if ($indent) {
            $this->outdent();
        }

        return $result;
    }

    /**
     * Pretty-print an infix operation while taking precedence into account.
     *
     * @param string $class Node class of operator
     * @param Node $leftNode Left-hand side node
     * @param string $operatorString String representation of the operator
     * @param Node $rightNode Right-hand side node
     * @param int $precedence Precedence of parent operator
     * @param int $lhsPrecedence Precedence for unary operator on LHS of binary operator
     *
     * @return string Pretty printed infix operation
     */
    protected function pInfixOp(
        string $class, Node $leftNode, string $operatorString, Node $rightNode,
        int $precedence, int $lhsPrecedence
    ): string {
        list($opPrecedence, $newPrecedenceLHS, $newPrecedenceRHS) = $this->precedenceMap[$class];
        $prefix = '';
        $suffix = '';
        if ($opPrecedence >= $precedence) {
            $prefix = '(';
            $suffix = ')';
            $lhsPrecedence = self::MAX_PRECEDENCE;
        }
        return $prefix . $this->p($leftNode, $newPrecedenceLHS, $newPrecedenceLHS)
            . $operatorString . $this->p($rightNode, $newPrecedenceRHS, $lhsPrecedence) . $suffix;
    }

    /**
     * Pretty-print a prefix operation while taking precedence into account.
     *
     * @param string $class Node class of operator
     * @param string $operatorString String representation of the operator
     * @param Node $node Node
     * @param int $precedence Precedence of parent operator
     * @param int $lhsPrecedence Precedence for unary operator on LHS of binary operator
     *
     * @return string Pretty printed prefix operation
     */
    protected function pPrefixOp(string $class, string $operatorString, Node $node, int $precedence, int $lhsPrecedence): string {
        $opPrecedence = $this->precedenceMap[$class][0];
        $prefix = '';
        $suffix = '';
        if ($opPrecedence >= $lhsPrecedence) {
            $prefix = '(';
            $suffix = ')';
            $lhsPrecedence = self::MAX_PRECEDENCE;
        }
        $printedArg = $this->p($node, $opPrecedence, $lhsPrecedence);
        if (($operatorString === '+' && $printedArg[0] === '+') ||
            ($operatorString === '-' && $printedArg[0] === '-')
        ) {
            // Avoid printing +(+$a) as ++$a and similar.
            $printedArg = '(' . $printedArg . ')';
        }
        return $prefix . $operatorString . $printedArg . $suffix;
    }

    /**
     * Pretty-print a postfix operation while taking precedence into account.
     *
     * @param string $class Node class of operator
     * @param string $operatorString String representation of the operator
     * @param Node $node Node
     * @param int $precedence Precedence of parent operator
     * @param int $lhsPrecedence Precedence for unary operator on LHS of binary operator
     *
     * @return string Pretty printed postfix operation
     */
    protected function pPostfixOp(string $class, Node $node, string $operatorString, int $precedence, int $lhsPrecedence): string {
        $opPrecedence = $this->precedenceMap[$class][0];
        $prefix = '';
        $suffix = '';
        if ($opPrecedence >= $precedence) {
            $prefix = '(';
            $suffix = ')';
            $lhsPrecedence = self::MAX_PRECEDENCE;
        }
        if ($opPrecedence < $lhsPrecedence) {
            $lhsPrecedence = $opPrecedence;
        }
        return $prefix . $this->p($node, $opPrecedence, $lhsPrecedence) . $operatorString . $suffix;
    }

    /**
     * Pretty prints an array of nodes and implodes the printed values.
     *
     * @param Node[] $nodes Array of Nodes to be printed
     * @param string $glue Character to implode with
     *
     * @return string Imploded pretty printed nodes> $pre
     */
    protected function pImplode(array $nodes, string $glue = ''): string {
        $pNodes = [];
        foreach ($nodes as $node) {
            if ($node instanceof Leaf) {
                continue;
            }
            if (null === $node) {
                $pNodes[] = '';
            } else {
                $pNodes[] = $this->p($node);
            }
        }

        return implode($glue, $pNodes);
    }

    /**
     * Pretty prints an array of nodes and implodes the printed values with commas.
     *
     * @param Node[] $nodes Array of Nodes to be printed
     *
     * @return string Comma separated pretty printed nodes
     */
    protected function pCommaSeparated(array $nodes): string {
        return $this->pImplode($nodes, ', ');
    }

    /**
     * Pretty prints a comma-separated list of nodes in multiline style, including comments.
     *
     * The result includes a leading newline and one level of indentation (same as pStmts).
     *
     * @param Node[] $nodes Array of Nodes to be printed
     * @param bool $trailingComma Whether to use a trailing comma
     *
     * @return string Comma separated pretty printed nodes in multiline style
     */
    protected function pCommaSeparatedMultiline(array $nodes, bool $trailingComma): string {
        $nodes = $this->withoutLeaves($nodes);
        $this->indent();

        $result = '';
        $lastIdx = count($nodes) - 1;
        foreach ($nodes as $idx => $node) {
            if ($node !== null) {
                $comments = $node->getComments();
                if ($comments) {
                    $result .= $this->nl . $this->pComments($comments);
                }

                $result .= $this->nl . $this->p($node);
            } else {
                $result .= $this->nl;
            }
            if ($trailingComma || $idx !== $lastIdx) {
                $result .= ',';
            }
        }

        $this->outdent();
        return $result;
    }

    /**
     * Removes the leaf nodes of lossless layouts from an array of nodes.
     *
     * @template T of Node|null
     * @param array<T> $nodes Array of nodes
     * @return list<T> Nodes without leaves
     */
    protected function withoutLeaves(array $nodes): array {
        return Layout::withoutLeaves($nodes);
    }

    /**
     * Prints reformatted text of the passed comments.
     *
     * @param Comment[] $comments List of comments
     *
     * @return string Reformatted text of comments
     */
    protected function pComments(array $comments): string {
        $formattedComments = [];

        foreach ($comments as $comment) {
            $formattedComments[] = str_replace("\n", $this->nl, $comment->getReformattedText());
        }

        return implode($this->nl, $formattedComments);
    }

    /**
     * Perform a format-preserving pretty print of an AST.
     *
     * The format preservation is best effort. For some changes to the AST the formatting will not
     * be preserved (at least not locally).
     *
     * The original AST is annotated with lossless layouts (see LayoutAnnotatingVisitor), which
     * are then transferred to the modified AST (see LayoutTransferVisitor), so that the modified
     * AST can be printed from its layouts. Nodes that were modified in a way their layout cannot
     * express are printed by the regular pretty printer.
     *
     * In order to use this method a number of prerequisites must be satisfied:
     *  * The CloningVisitor must be run on the AST prior to modification.
     *  * The original tokens must be provided, using the getTokens() method on the parser.
     *
     * @param Node[] $stmts Modified AST with links to original AST
     * @param Node[] $origStmts Original AST with token offset information
     * @param Token[] $origTokens Tokens of the original code
     */
    public function printFormatPreserving(array $stmts, array $origStmts, array $origTokens): string {
        $origStmts = LayoutAnnotatingVisitor::annotate($origStmts, $origTokens);
        $traverser = new NodeTraverser(new LayoutTransferVisitor($origStmts));
        $stmts = $traverser->traverse($stmts);

        $this->resetState();
        $this->preprocessNodes($stmts);

        if (!$this->containsLeaves($stmts)) {
            // The original code was empty, there is nothing to preserve.
            if (!$stmts) {
                return '';
            }
            // TODO Add <?php properly
            return $this->handleMagicTokens("<?php" . $this->newline . $this->pStmts($stmts, false));
        }

        $origUseLayouts = $this->useLayouts;
        $this->useLayouts = true;
        try {
            $result = $this->pLayoutArray(null, 'stmts', $stmts, '');
        } finally {
            $this->useLayouts = $origUseLayouts;
        }
        return $this->handleMagicTokens($result);
    }

    /**
     * Pretty prints a node.
     *
     * If layouts are enabled (lossless printer, format-preserving print) and the node carries a
     * layout that still describes it (or can be repaired to do so), the node is printed from
     * its layout, which reproduces the original formatting.
     *
     * @param Node $node Node to be pretty printed
     * @param int $precedence Precedence of parent operator
     * @param int $lhsPrecedence Precedence for unary operator on LHS of binary operator
     * @param bool $parentFormatPreserved Whether parent node has preserved formatting
     *
     * @return string Pretty printed node
     */
    protected function p(
        Node $node, int $precedence = self::MAX_PRECEDENCE, int $lhsPrecedence = self::MAX_PRECEDENCE,
        bool $parentFormatPreserved = false
    ): string {
        if ($node instanceof Leaf) {
            return $node->text;
        }

        // InlineHTML nodes do not contain the closing and opening PHP tags, these are part of the
        // surrounding leaves. If the parent formatting is not preserved, the fallback code has
        // to print them.
        if ($this->useLayouts && (!$node instanceof Stmt\InlineHTML || $parentFormatPreserved)) {
            $layout = $this->getPrintableLayout($node);
            if ($layout !== null) {
                $result = $this->pLayout($node, $layout, $precedence, $lhsPrecedence);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return $this->{'p' . $node->getType()}($node, $precedence, $lhsPrecedence);
    }

    /**
     * Returns the layout of a node if it can be used for printing, repairing it if necessary.
     */
    protected function getPrintableLayout(Node $node): ?Layout {
        $layout = Layout::of($node);
        if ($layout === null) {
            return null;
        }
        if ($layout->matches($node)) {
            return $layout;
        }

        $repaired = $this->repairLayout($node, $layout);
        if ($repaired !== null) {
            $node->setAttribute(Layout::ATTRIBUTE, $repaired);
        }
        return $repaired;
    }

    /**
     * Prints a node from its (valid) layout.
     *
     * @return string|null Printed node, or null if the layout cannot be printed (an element was
     *                     inserted into a list for which no separator is known)
     */
    protected function pLayout(Node $node, Layout $layout, int $precedence, int $lhsPrecedence): ?string {
        $indentAdjustment = $this->indentLevel - $this->getIndentWidth($layout->indentation);
        $result = '';
        $children = $layout->children;
        foreach ($children as $i => $child) {
            if ($child instanceof Leaf) {
                $result .= $this->pLeaf($child, $indentAdjustment);
                continue;
            }
            if (!$child instanceof SubNodeRef) {
                throw new \LogicException('Layout children must be leaves or sub-node references');
            }

            $value = $child->resolve($node);
            $owner = $child->resolveOwner($node);
            $ownerKey = \count($child->path) > 1 ? self::ANON_CLASS : ($owner !== null ? \get_class($owner) : null);
            if ($value instanceof Node) {
                $this->safeAppend($result, $this->pLayoutChild($owner, $child->getName(), $value, $children, $i, $result));
            } elseif (\is_array($value)) {
                $printed = $this->pLayoutArray($owner, $child->getName(), $value, $result, $indentAdjustment, $ownerKey, $children, $i);
                if ($printed === null) {
                    return null;
                }
                $this->safeAppend($result, $printed);
            }
        }

        return $this->pLayoutParens($node, $result, $precedence, $lhsPrecedence);
    }

    /**
     * Prints a leaf, adjusting the indentation of line breaks in whitespace and comments.
     */
    protected function pLeaf(Leaf $leaf, int $indentAdjustment): string {
        $text = $leaf->text;
        if ($indentAdjustment === 0 || !$leaf->isTrivia()) {
            return $text;
        }
        // TODO Handle non-space indentation
        if ($indentAdjustment < 0) {
            return \str_replace("\n" . \str_repeat(" ", -$indentAdjustment), "\n", $text);
        }
        return \str_replace("\n", "\n" . \str_repeat(" ", $indentAdjustment), $text);
    }

    /**
     * Prints an array sub-node, including the leaves stored in-between its elements.
     *
     * Elements that are not at their original position (inserted or moved elements) are
     * separated from adjacent elements and comments by a default separator, and their comments
     * are printed if they are not already present as leaves.
     *
     * @param Node|null $owner Node owning the array (null for the top level)
     * @param string $name Name of the array sub-node
     * @param array<Node|null> $items Array elements, interleaved with leaves
     * @param string $resultSoFar Output of the parent printed so far (used to determine indentation)
     * @param int $indentAdjustment Indentation adjustment for the leaves of the array
     * @param string|null $ownerKey Key of the owner in the list maps (class name or ANON_CLASS)
     * @param array<Leaf|SubNodeRef> $parentChildren Children of the layout referencing the array
     * @param int $refIndex Index of the reference to the array within $parentChildren
     * @return string|null Printed array, or null if an element was inserted into a list for
     *                     which no separator is known
     */
    protected function pLayoutArray(
        ?Node $owner, string $name, array $items, string $resultSoFar, int $indentAdjustment = 0,
        ?string $ownerKey = null, array $parentChildren = [], int $refIndex = 0
    ): ?string {
        $items = \array_values($items);
        $isEncapsed = $owner instanceof Scalar\InterpolatedString || $owner instanceof Expr\ShellExec;
        $result = '';
        /** @var Node|Leaf|null $prev Previous item (leaves are nodes as well!) */
        $prev = null;
        $prevOriginal = true;
        $siblings = null;
        foreach ($items as $i => $item) {
            if ($item instanceof Leaf) {
                $text = $this->pLeaf($item, $indentAdjustment);
                if ($item instanceof Leaf\Comment && $prev !== null && !$prev instanceof Leaf && !$prevOriginal) {
                    // Inserted node directly followed by a comment.
                    $separator = $this->pListSeparator($owner, $name, $items, $item, $ownerKey, $resultSoFar . $result);
                    if ($separator === null) {
                        return null;
                    }
                    $result .= $separator;
                }
                $result .= $text;
                $prev = $item;
                $prevOriginal = true;
                continue;
            }
            if (!$item instanceof Node) {
                continue;
            }

            $isOriginal = $this->isOriginalChild($owner, $item);
            $isInlineHtml = $item instanceof Stmt\InlineHTML;
            $prevIsNode = $prev !== null && !$prev instanceof Leaf;
            if (!$isInlineHtml && !$isEncapsed
                && ($prevIsNode || $prev instanceof Leaf\Comment)
                && (!$isOriginal || ($prevIsNode && !$prevOriginal))
            ) {
                // Inline HTML is naturally adjacent to the surrounding statements (the PHP tags
                // are part of the statements).
                $separator = $this->pListSeparator($owner, $name, $items, $item, $ownerKey, $resultSoFar . $result);
                if ($separator === null) {
                    return null;
                }
                $result .= $separator;
            }

            if (!$isOriginal && !$isInlineHtml && !$isEncapsed) {
                $comments = $item->getComments();
                if ($comments && !$this->haveCommentsBefore($items, $i, $comments)) {
                    [, $newline] = $this->getListSeparator($owner, $name, $items, $item, $ownerKey);
                    if ($newline) {
                        $nl = $this->getNewlineAtEnd($resultSoFar . $result);
                        $result .= \str_replace("\n", $nl, $this->pComments($comments)) . $nl;
                    }
                }
            }

            if ($siblings === null && !$isOriginal && $parentChildren) {
                // The leaves around the array in the parent layout are relevant for fixups of
                // the first and last elements.
                $siblings = \array_merge(
                    \array_slice($parentChildren, 0, $refIndex), $items, \array_slice($parentChildren, $refIndex + 1)
                );
            }
            $printed = $siblings !== null
                ? $this->pLayoutChild($owner, $name, $item, $siblings, $refIndex + $i, $resultSoFar . $result)
                : $this->pLayoutChild($owner, $name, $item, $items, $i, $resultSoFar . $result);
            if ($isEncapsed) {
                $result .= $printed;
            } else {
                $this->safeAppend($result, $printed);
            }
            $prev = $isInlineHtml ? null : $item;
            $prevOriginal = $isOriginal;
        }
        return $result;
    }

    /**
     * Prints the separator in front of an inserted list element.
     *
     * @param array<Node|null> $items
     * @return string|null Separator, or null if no separator is known for the list
     */
    protected function pListSeparator(
        ?Node $owner, string $name, array $items, Node $next, ?string $ownerKey, string $resultSoFar
    ): ?string {
        [$separator, $newline] = $this->getListSeparator($owner, $name, $items, $next, $ownerKey);
        if ($separator === null) {
            return null;
        }
        if ($newline) {
            $separator .= $this->getNewlineAtEnd($resultSoFar);
        }
        return $separator;
    }

    /**
     * Whether the comments are already present as leaves directly in front of the given item.
     *
     * @param array<Node|null> $items
     * @param \PhpParser\Comment[] $comments
     */
    protected function haveCommentsBefore(array $items, int $index, array $comments): bool {
        $texts = [];
        for ($i = $index - 1; $i >= 0; $i--) {
            $item = $items[$i];
            if (!$item instanceof Leaf || !$item->isTrivia()) {
                break;
            }
            if ($item instanceof Leaf\Comment) {
                $texts[\preg_replace('/\s+/', '', $item->text)] = true;
            }
        }
        foreach ($comments as $comment) {
            if (!isset($texts[\preg_replace('/\s+/', '', $comment->getText())])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Prints a child node referenced from a layout (or contained in a laid out array).
     *
     * Children that are not the original children of their parent (replaced or moved nodes)
     * are printed with fixups: parentheses or braces are added if required by their position.
     *
     * @param array<Leaf|SubNodeRef|Node|null> $siblings Children of the layout or array containing the child
     * @param int $index Index of the child within $siblings
     */
    protected function pLayoutChild(
        ?Node $owner, string $name, Node $child, array $siblings, int $index, string $resultSoFar
    ): string {
        if ($child instanceof Node\InterpolatedStringPart && Layout::of($child) === null) {
            // String parts have no printer method of their own.
            return $this->pLayoutStringPart($child, $owner);
        }

        // Multi-line output is indented relative to the line the child starts on.
        $origIndentLevel = $this->indentLevel;
        $this->setIndentLevel($this->getIndentationAtEnd($resultSoFar, $origIndentLevel));

        $fixup = null;
        if ($owner !== null && !$this->isOriginalChild($owner, $child)) {
            $this->initializeFixupMap();
            $fixup = $this->fixupMap[\get_class($owner)][$name] ?? null;
        }
        if ($fixup === null) {
            $result = $this->p($child, self::MAX_PRECEDENCE, self::MAX_PRECEDENCE, true);
        } else {
            $result = $this->pLayoutFixup($fixup, $child, \get_class($owner), $siblings, $index);
        }

        $this->setIndentLevel($origIndentLevel);
        return $result;
    }

    /**
     * Prints an interpolated string part that has no layout.
     */
    protected function pLayoutStringPart(Node\InterpolatedStringPart $part, ?Node $owner): string {
        throw new \LogicException('Printing of interpolated string parts is not supported by this printer');
    }

    /**
     * Whether the child is at the position it had when its layout was created, in which case
     * it does not need any fixups and its comments are part of the leaves.
     */
    protected function isOriginalChild(?Node $owner, Node $child): bool {
        $layout = Layout::of($child);
        if ($layout === null) {
            return false;
        }
        if ($owner === null) {
            return $layout->isTopLevel();
        }
        $parent = $layout->getParent();
        return $parent !== null && ($parent === $owner || $parent === $owner->getAttribute('origNode'));
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
     * Fixups here refer to the addition of extra parentheses, braces or other characters, that
     * are required to preserve program semantics in a certain context (e.g. to maintain precedence
     * or because only certain expressions are allowed in certain places). The presence of
     * parentheses in the original code is determined from the surrounding leaves.
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
     * Determines the separator to insert in front of an element that was inserted into a list
     * without leaves.
     *
     * @param array<Node|null> $items Array elements, interleaved with leaves
     * @param string|null $ownerKey Key of the owner in the list maps (class name or ANON_CLASS)
     * @return array{string|null, bool} Separator (null if unknown) and whether a line break
     *                                  (with indentation) follows it
     */
    protected function getListSeparator(?Node $owner, string $name, array $items, Node $next, ?string $ownerKey): array {
        if ($owner === null) {
            $insertStr = "\n";
        } elseif ($owner instanceof Scalar\InterpolatedString || $owner instanceof Expr\ShellExec) {
            // String parts are naturally adjacent.
            return ['', false];
        } else {
            $this->initializeListInsertionMap();
            $ownerKey = $ownerKey ?? \get_class($owner);
            $insertStr = $this->listInsertionMap[$ownerKey . '->' . $name]
                ?? $this->listInsertionMap[\get_class($owner) . '->' . $name] ?? null;
            if ($insertStr === null) {
                // We don't have insertion information for this list type
                return [null, false];
            }
        }
        if ($insertStr === "\n") {
            return ['', true];
        }
        // We go multiline if the original code was multiline, or if it's an array item with a
        // comment above it. Match always uses multiline formatting.
        if ($insertStr === ', ' &&
            ($this->isMultilineList($items) || $next->getComments() || $owner instanceof Expr\Match_)
        ) {
            return [',', true];
        }
        return [$insertStr, false];
    }

    /**
     * Determine whether a list of nodes uses multiline formatting, i.e. whether there is a line
     * break between all elements that have leaves between them.
     *
     * @param array<Node|null> $items Array elements, interleaved with leaves
     */
    protected function isMultilineList(array $items): bool {
        $gaps = [];
        $current = null;
        foreach ($items as $item) {
            if ($item instanceof Leaf) {
                if ($current !== null) {
                    $current[0] = true;
                    if ($item instanceof Leaf\Whitespace && $item->hasNewline()) {
                        $current[1] = true;
                    }
                }
            } elseif ($item instanceof Node) {
                if ($current !== null) {
                    $gaps[] = $current;
                }
                $current = [false, false];
            }
        }

        $haveGapWithLeaves = false;
        foreach ($gaps as [$hasLeaves, $hasNewline]) {
            if (!$hasLeaves) {
                // Inserted element, does not tell us anything about the original formatting.
                continue;
            }
            if (!$hasNewline) {
                // We require that a newline is present between *every* item. If the formatting
                // is inconsistent, with only some items having newlines, we don't consider it
                // as multiline
                return false;
            }
            $haveGapWithLeaves = true;
        }
        return $haveGapWithLeaves;
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
     * Determines the indentation (in columns) of the last line of the given text, or the
     * default if the text does not contain a line break.
     */
    protected function getIndentationAtEnd(string $text, int $default): int {
        $nlPos = \strrpos($text, "\n");
        if ($nlPos === false) {
            return $default;
        }
        return $this->getIndentWidth(\substr($text, $nlPos + 1));
    }

    /**
     * Returns a newline followed by the indentation of the last line of the given text.
     */
    protected function getNewlineAtEnd(string $text): string {
        $origIndentLevel = $this->indentLevel;
        $this->setIndentLevel($this->getIndentationAtEnd($text, $origIndentLevel));
        $nl = $this->nl;
        $this->setIndentLevel($origIndentLevel);
        return $nl;
    }

    /**
     * Determines the width (in columns) of the leading whitespace of a string.
     */
    protected function getIndentWidth(string $text): int {
        $indent = 0;
        for ($i = 0, $len = \strlen($text); $i < $len; $i++) {
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

    /*
     * Layout repair: Adjusting a layout to modifications of the node that the layout does not
     * describe (modifier changes, added or removed optional sub-nodes, elements added to
     * previously empty lists).
     */

    /**
     * Tries to adjust the layout to the current state of the node.
     *
     * @return Layout|null Repaired layout or null if the layout cannot be repaired
     */
    protected function repairLayout(Node $node, Layout $layout): ?Layout {
        $this->initializeRemovalMap();
        $this->initializeInsertionMap();
        $this->initializeEmptyListInsertionMap();
        $this->initializeModifierChangeMap();

        $children = $layout->children;
        $snapshot = $layout->getSnapshot();

        $owners = ['' => $node];
        foreach ($layout->getHoistedSubNodes() as $name => $_) {
            $hoisted = $node->$name ?? null;
            if (!$hoisted instanceof Node) {
                return null;
            }
            $owners[$name . '.'] = $hoisted;
        }

        foreach ($owners as $prefix => $owner) {
            $context = new Internal\LayoutRepairContext($node, $owner, $prefix, $layout->indentation);
            foreach ($owner->getSubNodeNames() as $name) {
                if ($prefix !== '' && $name === 'name') {
                    // The name of an anonymous class is not tracked.
                    continue;
                }
                $value = $owner->$name;
                $key = $prefix . $name;
                if ($value instanceof Node) {
                    if ($value instanceof Leaf) {
                        return null;
                    }
                    if (\array_key_exists($key, $snapshot)) {
                        if ($snapshot[$key] !== null) {
                            return null;
                        }
                        // Sub-node was added.
                        if (!$this->insertSubNodeIntoLayout($children, $context, $name)) {
                            return null;
                        }
                        unset($snapshot[$key]);
                    }
                } elseif (\is_array($value)) {
                    $nonNodes = [];
                    $hasNodes = false;
                    foreach ($value as $i => $item) {
                        if (!$item instanceof Node) {
                            $nonNodes[$i] = $item;
                        } elseif (!$item instanceof Leaf) {
                            $hasNodes = true;
                        }
                    }
                    if (($snapshot[$key] ?? null) !== $nonNodes) {
                        return null;
                    }
                    if ($hasNodes && !$this->layoutHasRef($children, $key)) {
                        // Elements were added to an empty list.
                        if (!$this->insertListIntoLayout($children, $context, $name)) {
                            return null;
                        }
                    }
                } else {
                    if (!\array_key_exists($key, $snapshot)) {
                        // Sub-node was removed.
                        if ($value !== null || !$this->removeSubNodeFromLayout($children, $context, $name)) {
                            return null;
                        }
                        $snapshot[$key] = null;
                    } elseif ($snapshot[$key] !== $value) {
                        // Scalar sub-node changed: Only modifier changes can be handled.
                        if (!$this->changeModifiersInLayout($children, $context, $name, $value)) {
                            return null;
                        }
                        $snapshot[$key] = $value;
                    }
                }
            }
        }

        $repaired = new Layout($children, $snapshot, $layout->indentation);
        $repaired->setParent($layout->getParent());
        return $repaired->matches($node) ? $repaired : null;
    }

    /**
     * @param array<Leaf|SubNodeRef> $children
     */
    private function layoutHasRef(array $children, string $key): bool {
        foreach ($children as $child) {
            if ($child instanceof SubNodeRef && $child->getKey() === $key) {
                return true;
            }
        }
        return false;
    }

    /**
     * Looks up a map entry for a sub-node, using the anonymous class entries for hoisted
     * sub-nodes.
     *
     * @param array<string, mixed> $map
     * @return mixed
     */
    private function lookupLayoutMap(array $map, Internal\LayoutRepairContext $context, string $name, bool $useType) {
        $ownerKey = $useType ? $context->owner->getType() : \get_class($context->owner);
        if ($context->prefix !== '' && isset($map[self::ANON_CLASS . '->' . $name])) {
            return $map[self::ANON_CLASS . '->' . $name];
        }
        return $map[$ownerKey . '->' . $name] ?? null;
    }

    /**
     * Determines the index in the layout children at which a sub-node would be located: directly
     * after the last reference to a sub-node preceding it in the sub-node order.
     *
     * @param array<Leaf|SubNodeRef> $children
     */
    private function getLayoutPosition(array $children, Internal\LayoutRepairContext $context, string $name): int {
        $previous = [];
        foreach ($context->owner->getSubNodeNames() as $subName) {
            if ($subName === $name) {
                break;
            }
            $previous[$context->prefix . $subName] = true;
        }
        if ($context->prefix !== '') {
            // Anonymous class: The constructor arguments precede the extends/implements clauses
            // and the class body.
            if ($name === 'extends' || $name === 'implements' || $name === 'stmts') {
                $previous['args'] = true;
            }
        } elseif ($name === 'args' && $context->node instanceof Expr\New_ && $context->node->class instanceof Stmt\Class_) {
            $previous['class.attrGroups'] = true;
            $previous['class.name'] = true;
        }

        $pos = 0;
        foreach ($children as $i => $child) {
            if ($child instanceof SubNodeRef && isset($previous[$child->getKey()])) {
                $pos = $i + 1;
            }
        }
        return $pos;
    }

    /**
     * Finds the next token of the given type in the layout children, starting at $from. The
     * first token of a referenced sub-node is considered as well.
     *
     * @param array<Leaf|SubNodeRef> $children
     * @param int|string $tokenType
     * @return array{int, bool}|null Index and whether the token is inside a referenced sub-node
     */
    private function findLayoutToken(array $children, int $from, Node $node, $tokenType): ?array {
        for ($i = $from, $n = \count($children); $i < $n; $i++) {
            $child = $children[$i];
            if ($child instanceof Leaf) {
                if ($this->leafMatches($child, $tokenType)) {
                    return [$i, false];
                }
                continue;
            }
            if ($child instanceof SubNodeRef) {
                $first = $this->getFirstLeaf($child->resolve($node));
                if ($first !== null && $this->leafMatches($first, $tokenType)) {
                    return [$i, true];
                }
            }
        }
        return null;
    }

    /**
     * @param Node|array<Node|null>|null $value
     */
    private function getFirstLeaf($value): ?Leaf {
        for ($depth = 0; $depth < 100; $depth++) {
            if ($value instanceof Leaf) {
                return $value;
            }
            if (\is_array($value)) {
                $value = \reset($value) ?: null;
                continue;
            }
            if (!$value instanceof Node) {
                return null;
            }
            $layout = Layout::of($value);
            if ($layout === null || !$layout->children) {
                return null;
            }
            $first = $layout->children[0];
            $value = $first instanceof SubNodeRef ? $first->resolve($value) : $first;
        }
        return null;
    }

    /**
     * Whether a leaf corresponds to a token of the given type.
     *
     * @param int|string $tokenType Token type, as used by Token::is()
     */
    protected function leafMatches(Leaf $leaf, $tokenType): bool {
        if (\is_string($tokenType)) {
            return $leaf instanceof Leaf\Token && $leaf->text === $tokenType;
        }
        if ($tokenType === \T_WHITESPACE) {
            return $leaf instanceof Leaf\Whitespace;
        }
        if ($tokenType === \T_COMMENT || $tokenType === \T_DOC_COMMENT) {
            return $leaf instanceof Leaf\Comment;
        }
        if (!$leaf instanceof Leaf\Token) {
            return false;
        }
        if ($tokenType === \T_VARIABLE) {
            return $leaf->text[0] === '$' && \strlen($leaf->text) > 1;
        }
        if ($tokenType === \T_STRING) {
            return 1 === \preg_match('/\A[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\z/', $leaf->text);
        }
        if ($tokenType === \T_DOUBLE_ARROW) {
            return $leaf->text === '=>';
        }
        $name = \token_name($tokenType);
        return \strncmp($name, 'T_', 2) === 0 && \strtolower($leaf->text) === \strtolower(\substr($name, 2));
    }

    /**
     * Converts text to leaves, splitting it into whitespace and token leaves.
     *
     * @return list<Leaf>
     */
    protected function textToLeaves(string $text): array {
        $leaves = [];
        \preg_match_all('/\s+|\S+/', $text, $matches);
        foreach ($matches[0] as $part) {
            $leaves[] = \ctype_space($part) ? new Leaf\Whitespace($part) : new Leaf\Token($part);
        }
        return $leaves;
    }

    /**
     * Skips trivia leaves and optionally one token of the given type.
     *
     * @param array<Leaf|SubNodeRef> $children
     * @param int|string $tokenType
     * @return int|null Index after the skipped leaves, or null if the expected token is missing
     */
    private function skipLayoutLeaves(array $children, int $pos, $tokenType): ?int {
        $n = \count($children);
        while ($pos < $n && $children[$pos] instanceof Leaf && $children[$pos]->isTrivia()) {
            $pos++;
        }
        if ($tokenType === \T_WHITESPACE) {
            return $pos;
        }
        if ($pos >= $n || !$children[$pos] instanceof Leaf || !$this->leafMatches($children[$pos], $tokenType)) {
            return null;
        }
        $pos++;
        while ($pos < $n && $children[$pos] instanceof Leaf && $children[$pos]->isTrivia()) {
            $pos++;
        }
        return $pos;
    }

    /**
     * Inserts a reference to a sub-node that was previously null into the layout, together with
     * the tokens required around it.
     *
     * @param array<Leaf|SubNodeRef> $children
     */
    private function insertSubNodeIntoLayout(array &$children, Internal\LayoutRepairContext $context, string $name): bool {
        $info = $this->lookupLayoutMap($this->insertionMap, $context, $name, true);
        if ($info === null) {
            return false;
        }
        [$findToken, $beforeToken, $extraLeft, $extraRight] = $info;

        $pos = $this->getLayoutPosition($children, $context, $name);
        if ($findToken !== null) {
            $found = $this->findLayoutToken($children, $pos, $context->node, $findToken);
            if ($found === null) {
                return false;
            }
            [$idx, $insideRef] = $found;
            if ($insideRef && !$beforeToken) {
                return false;
            }
            $pos = $beforeToken ? $idx : $idx + 1;
        }
        if ($extraLeft === null && $extraRight !== null) {
            // If inserting on the right only, skipping whitespace looks better
            $pos = $this->skipLayoutLeaves($children, $pos, \T_WHITESPACE);
        }

        $insert = $extraLeft !== null ? $this->textToLeaves($extraLeft) : [];
        $insert[] = new SubNodeRef(...$context->path($name));
        if ($extraRight !== null) {
            $insert = \array_merge($insert, $this->textToLeaves($extraRight));
        }
        \array_splice($children, $pos, 0, $insert);
        return true;
    }

    /**
     * Removes the reference to a sub-node that is now null from the layout, together with the
     * tokens that belonged to it.
     *
     * @param array<Leaf|SubNodeRef> $children
     */
    private function removeSubNodeFromLayout(array &$children, Internal\LayoutRepairContext $context, string $name): bool {
        $info = $this->lookupLayoutMap($this->removalMap, $context, $name, true);
        if ($info === null) {
            return false;
        }

        $key = $context->prefix . $name;
        $refIdx = null;
        foreach ($children as $i => $child) {
            if ($child instanceof SubNodeRef && $child->getKey() === $key) {
                $refIdx = $i;
                break;
            }
        }
        if ($refIdx === null) {
            return false;
        }

        $start = $refIdx;
        $end = $refIdx + 1;
        if (isset($info['left'])) {
            $start = $this->skipLayoutLeavesBackward($children, $start, $info['left']);
            if ($start === null) {
                return false;
            }
        }
        if (isset($info['right'])) {
            $end = $this->skipLayoutLeaves($children, $end, $info['right']);
            if ($end === null) {
                return false;
            }
        }
        \array_splice($children, $start, $end - $start);
        return true;
    }

    /**
     * Backward variant of skipLayoutLeaves(): Returns the index of the first leaf to keep.
     *
     * @param array<Leaf|SubNodeRef> $children
     * @param int|string $tokenType
     */
    private function skipLayoutLeavesBackward(array $children, int $pos, $tokenType): ?int {
        while ($pos > 0 && $children[$pos - 1] instanceof Leaf && $children[$pos - 1]->isTrivia()) {
            $pos--;
        }
        if ($tokenType === \T_WHITESPACE) {
            return $pos;
        }
        if ($pos === 0 || !$children[$pos - 1] instanceof Leaf || !$this->leafMatches($children[$pos - 1], $tokenType)) {
            return null;
        }
        $pos--;
        while ($pos > 0 && $children[$pos - 1] instanceof Leaf && $children[$pos - 1]->isTrivia()) {
            $pos--;
        }
        return $pos;
    }

    /**
     * Inserts a reference to a previously empty list into the layout, together with the tokens
     * required around it.
     *
     * @param array<Leaf|SubNodeRef> $children
     */
    private function insertListIntoLayout(array &$children, Internal\LayoutRepairContext $context, string $name): bool {
        $info = $this->lookupLayoutMap($this->emptyListInsertionMap, $context, $name, false);
        if ($info === null) {
            return false;
        }
        [$findToken, $extraLeft, $extraRight] = $info;

        $pos = $this->getLayoutPosition($children, $context, $name);
        if ($findToken !== null) {
            $isAnonClassArgs = $name === 'args' && $context->node instanceof Expr\New_
                && $context->node->class instanceof Stmt\Class_;
            if ($isAnonClassArgs) {
                // For anon classes skip to the class keyword.
                $found = $this->findLayoutToken($children, $pos, $context->node, \T_CLASS);
                if ($found === null || $found[1]) {
                    return false;
                }
                $pos = $found[0] + 1;
            }

            // If "new Foo" was used without arguments, we need to convert to "new Foo()".
            $next = $this->skipLayoutLeaves($children, $pos, \T_WHITESPACE);
            $haveParen = isset($children[$next]) && $children[$next] instanceof Leaf
                && $this->leafMatches($children[$next], '(');
            if (($context->node instanceof Expr\New_ && $name === 'args') && !$haveParen) {
                $extraLeft = '(';
                $extraRight = ')';
            } else {
                $found = $this->findLayoutToken($children, $pos, $context->node, $findToken);
                if ($found === null || $found[1]) {
                    return false;
                }
                $pos = $found[0] + 1;
            }
        }

        $insert = $this->textToLeaves($extraLeft);
        $insert[] = new SubNodeRef(...$context->path($name));
        if ($extraRight === "\n") {
            $insert[] = new Leaf\Whitespace("\n" . $context->indentation);
        } else {
            $insert = \array_merge($insert, $this->textToLeaves($extraRight));
        }
        \array_splice($children, $pos, 0, $insert);
        return true;
    }

    /**
     * Replaces the modifier tokens in the layout after a change of a flags/static sub-node.
     *
     * @param array<Leaf|SubNodeRef> $children
     * @param mixed $value New value of the sub-node
     */
    private function changeModifiersInLayout(array &$children, Internal\LayoutRepairContext $context, string $name, $value): bool {
        $info = $this->lookupLayoutMap($this->modifierChangeMap, $context, $name, false);
        if ($info === null) {
            return false;
        }
        [$printFn, $skipToken, $findToken] = $info;

        $pos = $this->getLayoutPosition($children, $context, $name);
        $start = $this->skipLayoutLeaves($children, $pos, $skipToken);
        if ($start === null) {
            return false;
        }
        $found = $this->findLayoutToken($children, $start, $context->node, $findToken);
        if ($found === null) {
            return false;
        }
        // Only the leaves in front of the first sub-node are replaced, sub-nodes (e.g. the type
        // of a property) and the leaves after them are kept.
        $end = $found[0];
        for ($i = $start; $i < $end; $i++) {
            if ($children[$i] instanceof SubNodeRef) {
                $end = $i;
                break;
            }
        }
        \array_splice($children, $start, $end - $start, $this->textToLeaves($this->$printFn($value)));
        return true;
    }

    /**
     * Appends to a string, ensuring whitespace between label characters.
     *
     * Example: "echo" and "$x" result in "echo$x", but "echo" and "x" result in "echo x".
     * Without safeAppend the result would be "echox", which does not preserve semantics.
     */
    protected function safeAppend(string &$str, string $append): void {
        if (!isset($this->labelCharMap)) {
            $this->initializeLabelCharMap();
        }
        if ($str === "") {
            $str = $append;
            return;
        }

        if ($append === "") {
            return;
        }

        if (!$this->labelCharMap[$append[0]]
                || !$this->labelCharMap[$str[\strlen($str) - 1]]) {
            $str .= $append;
        } else {
            $str .= " " . $append;
        }
    }

    /**
     * Determines whether the LHS of a call must be wrapped in parenthesis.
     *
     * @param Node $node LHS of a call
     *
     * @return bool Whether parentheses are required
     */
    protected function callLhsRequiresParens(Node $node): bool {
        if ($node instanceof Expr\New_) {
            return !$this->phpVersion->supportsNewDereferenceWithoutParentheses();
        }
        return !($node instanceof Node\Name
            || $node instanceof Expr\Variable
            || $node instanceof Expr\ArrayDimFetch
            || $node instanceof Expr\FuncCall
            || $node instanceof Expr\MethodCall
            || $node instanceof Expr\NullsafeMethodCall
            || $node instanceof Expr\StaticCall
            || $node instanceof Expr\Array_);
    }

    /**
     * Determines whether the LHS of an array/object operation must be wrapped in parentheses.
     *
     * @param Node $node LHS of dereferencing operation
     *
     * @return bool Whether parentheses are required
     */
    protected function dereferenceLhsRequiresParens(Node $node): bool {
        // A constant can occur on the LHS of an array/object deref, but not a static deref.
        return !$node instanceof Expr\ConstFetch &&
            $this->staticDereferenceLhsRequiresParens($node);
    }

    /**
     * Determines whether the LHS of a static operation must be wrapped in parentheses.
     *
     * @param Node $node LHS of dereferencing operation
     *
     * @return bool Whether parentheses are required
     */
    protected function staticDereferenceLhsRequiresParens(Node $node): bool {
        if ($node instanceof Expr\New_) {
            return !$this->phpVersion->supportsNewDereferenceWithoutParentheses();
        }
        return !($node instanceof Expr\Variable
            || $node instanceof Node\Name
            || $node instanceof Expr\ArrayDimFetch
            || $node instanceof Expr\PropertyFetch
            || $node instanceof Expr\NullsafePropertyFetch
            || $node instanceof Expr\StaticPropertyFetch
            || $node instanceof Expr\FuncCall
            || $node instanceof Expr\MethodCall
            || $node instanceof Expr\NullsafeMethodCall
            || $node instanceof Expr\StaticCall
            || $node instanceof Expr\Array_
            || $node instanceof Scalar\String_
            || $node instanceof Expr\ClassConstFetch);
    }

    /**
     * Determines whether an expression used in "new" or "instanceof" requires parentheses.
     *
     * @param Node $node New or instanceof operand
     *
     * @return bool Whether parentheses are required
     */
    protected function newOperandRequiresParens(Node $node): bool {
        while (true) {
            if ($node instanceof Node\Name || $node instanceof Expr\Variable) {
                return false;
            }
            if ($node instanceof Expr\ArrayDimFetch || $node instanceof Expr\PropertyFetch ||
                $node instanceof Expr\NullsafePropertyFetch
            ) {
                $node = $node->var;
                continue;
            }
            if ($node instanceof Expr\StaticPropertyFetch) {
                $node = $node->class;
                continue;
            }
            return true;
        }
    }

    /**
     * Print modifiers, including trailing whitespace.
     *
     * @param int $modifiers Modifier mask to print
     *
     * @return string Printed modifiers
     */
    protected function pModifiers(int $modifiers): string {
        return ($modifiers & Modifiers::FINAL ? 'final ' : '')
             . ($modifiers & Modifiers::ABSTRACT ? 'abstract ' : '')
             . ($modifiers & Modifiers::PUBLIC ? 'public ' : '')
             . ($modifiers & Modifiers::PROTECTED ? 'protected ' : '')
             . ($modifiers & Modifiers::PRIVATE ? 'private ' : '')
             . ($modifiers & Modifiers::PUBLIC_SET ? 'public(set) ' : '')
             . ($modifiers & Modifiers::PROTECTED_SET ? 'protected(set) ' : '')
             . ($modifiers & Modifiers::PRIVATE_SET ? 'private(set) ' : '')
             . ($modifiers & Modifiers::STATIC ? 'static ' : '')
             . ($modifiers & Modifiers::READONLY ? 'readonly ' : '');
    }

    protected function pStatic(bool $static): string {
        return $static ? 'static ' : '';
    }

    protected function initializeLabelCharMap(): void {
        if (isset($this->labelCharMap)) {
            return;
        }

        $this->labelCharMap = [];
        for ($i = 0; $i < 256; $i++) {
            $chr = chr($i);
            $this->labelCharMap[$chr] = (bool) preg_match('/^[a-zA-Z0-9_\x80-\xff]$/', $chr);
        }

        if ($this->phpVersion->allowsDelInIdentifiers()) {
            $this->labelCharMap["\x7f"] = true;
        }
    }

    /**
     * Lazily initializes fixup map.
     *
     * The fixup map is used to determine whether a certain subnode of a certain node may require
     * some kind of "fixup" operation, e.g. the addition of parenthesis or braces.
     */
    protected function initializeFixupMap(): void {
        if (isset($this->fixupMap)) {
            return;
        }

        $this->fixupMap = [
            Expr\Instanceof_::class => [
                'expr' => self::FIXUP_PREC_UNARY,
                'class' => self::FIXUP_NEW,
            ],
            Expr\Ternary::class => [
                'cond' => self::FIXUP_PREC_LEFT,
                'else' => self::FIXUP_PREC_RIGHT,
            ],
            Expr\Yield_::class => ['value' => self::FIXUP_PREC_UNARY],

            Expr\FuncCall::class => ['name' => self::FIXUP_CALL_LHS],
            Expr\StaticCall::class => ['class' => self::FIXUP_STATIC_DEREF_LHS],
            Expr\ArrayDimFetch::class => ['var' => self::FIXUP_DEREF_LHS],
            Expr\ClassConstFetch::class => [
                'class' => self::FIXUP_STATIC_DEREF_LHS,
                'name' => self::FIXUP_BRACED_NAME,
            ],
            Expr\New_::class => ['class' => self::FIXUP_NEW],
            Expr\MethodCall::class => [
                'var' => self::FIXUP_DEREF_LHS,
                'name' => self::FIXUP_BRACED_NAME,
            ],
            Expr\NullsafeMethodCall::class => [
                'var' => self::FIXUP_DEREF_LHS,
                'name' => self::FIXUP_BRACED_NAME,
            ],
            Expr\StaticPropertyFetch::class => [
                'class' => self::FIXUP_STATIC_DEREF_LHS,
                'name' => self::FIXUP_VAR_BRACED_NAME,
            ],
            Expr\PropertyFetch::class => [
                'var' => self::FIXUP_DEREF_LHS,
                'name' => self::FIXUP_BRACED_NAME,
            ],
            Expr\NullsafePropertyFetch::class => [
                'var' => self::FIXUP_DEREF_LHS,
                'name' => self::FIXUP_BRACED_NAME,
            ],
            Scalar\InterpolatedString::class => [
                'parts' => self::FIXUP_ENCAPSED,
            ],
        ];

        $binaryOps = [
            BinaryOp\Pow::class, BinaryOp\Mul::class, BinaryOp\Div::class, BinaryOp\Mod::class,
            BinaryOp\Plus::class, BinaryOp\Minus::class, BinaryOp\Concat::class,
            BinaryOp\ShiftLeft::class, BinaryOp\ShiftRight::class, BinaryOp\Smaller::class,
            BinaryOp\SmallerOrEqual::class, BinaryOp\Greater::class, BinaryOp\GreaterOrEqual::class,
            BinaryOp\Equal::class, BinaryOp\NotEqual::class, BinaryOp\Identical::class,
            BinaryOp\NotIdentical::class, BinaryOp\Spaceship::class, BinaryOp\BitwiseAnd::class,
            BinaryOp\BitwiseXor::class, BinaryOp\BitwiseOr::class, BinaryOp\BooleanAnd::class,
            BinaryOp\BooleanOr::class, BinaryOp\Coalesce::class, BinaryOp\LogicalAnd::class,
            BinaryOp\LogicalXor::class, BinaryOp\LogicalOr::class, BinaryOp\Pipe::class,
        ];
        foreach ($binaryOps as $binaryOp) {
            $this->fixupMap[$binaryOp] = [
                'left' => self::FIXUP_PREC_LEFT,
                'right' => self::FIXUP_PREC_RIGHT
            ];
        }

        $prefixOps = [
            Expr\Clone_::class, Expr\BitwiseNot::class, Expr\BooleanNot::class, Expr\UnaryPlus::class, Expr\UnaryMinus::class,
            Cast\Int_::class, Cast\Double::class, Cast\String_::class, Cast\Array_::class,
            Cast\Object_::class, Cast\Bool_::class, Cast\Unset_::class, Expr\ErrorSuppress::class,
            Expr\YieldFrom::class, Expr\Print_::class, Expr\Include_::class,
            Expr\Assign::class, Expr\AssignRef::class, AssignOp\Plus::class, AssignOp\Minus::class,
            AssignOp\Mul::class, AssignOp\Div::class, AssignOp\Concat::class, AssignOp\Mod::class,
            AssignOp\BitwiseAnd::class, AssignOp\BitwiseOr::class, AssignOp\BitwiseXor::class,
            AssignOp\ShiftLeft::class, AssignOp\ShiftRight::class, AssignOp\Pow::class, AssignOp\Coalesce::class,
            Expr\ArrowFunction::class, Expr\Throw_::class,
        ];
        foreach ($prefixOps as $prefixOp) {
            $this->fixupMap[$prefixOp] = ['expr' => self::FIXUP_PREC_UNARY];
        }
    }

    /**
     * Lazily initializes the removal map.
     *
     * The removal map is used to determine which additional tokens should be removed when a
     * certain node is replaced by null.
     */
    protected function initializeRemovalMap(): void {
        if (isset($this->removalMap)) {
            return;
        }

        $stripBoth = ['left' => \T_WHITESPACE, 'right' => \T_WHITESPACE];
        $stripLeft = ['left' => \T_WHITESPACE];
        $stripRight = ['right' => \T_WHITESPACE];
        $stripDoubleArrow = ['right' => \T_DOUBLE_ARROW];
        $stripColon = ['left' => ':'];
        $stripEquals = ['left' => '='];
        $this->removalMap = [
            'Expr_ArrayDimFetch->dim' => $stripBoth,
            'ArrayItem->key' => $stripDoubleArrow,
            'Expr_ArrowFunction->returnType' => $stripColon,
            'Expr_Closure->returnType' => $stripColon,
            'Expr_Exit->expr' => $stripBoth,
            'Expr_Ternary->if' => $stripBoth,
            'Expr_Yield->key' => $stripDoubleArrow,
            'Expr_Yield->value' => $stripBoth,
            'Param->type' => $stripRight,
            'Param->default' => $stripEquals,
            'Stmt_Break->num' => $stripBoth,
            'Stmt_Catch->var' => $stripLeft,
            'Stmt_ClassConst->type' => $stripRight,
            'Stmt_ClassMethod->returnType' => $stripColon,
            'Stmt_Class->extends' => ['left' => \T_EXTENDS],
            'Stmt_Enum->scalarType' => $stripColon,
            'Stmt_EnumCase->expr' => $stripEquals,
            self::ANON_CLASS . '->extends' => ['left' => \T_EXTENDS],
            'Stmt_Continue->num' => $stripBoth,
            'Stmt_Foreach->keyVar' => $stripDoubleArrow,
            'Stmt_Function->returnType' => $stripColon,
            'Stmt_If->else' => $stripLeft,
            'Stmt_Namespace->name' => $stripLeft,
            'Stmt_Property->type' => $stripRight,
            'PropertyItem->default' => $stripEquals,
            'Stmt_Return->expr' => $stripBoth,
            'Stmt_StaticVar->default' => $stripEquals,
            'Stmt_TraitUseAdaptation_Alias->newName' => $stripLeft,
            'Stmt_TryCatch->finally' => $stripLeft,
            // 'Stmt_Case->cond': Replace with "default"
            // 'Stmt_Class->name': Unclear what to do
            // 'Stmt_Declare->stmts': Not a plain node
            // 'Stmt_TraitUseAdaptation_Alias->newModifier': Not a plain node
        ];
    }

    protected function initializeInsertionMap(): void {
        if (isset($this->insertionMap)) {
            return;
        }

        // TODO: "yield" where both key and value are inserted doesn't work
        // [$find, $beforeToken, $extraLeft, $extraRight]
        $this->insertionMap = [
            'Expr_ArrayDimFetch->dim' => ['[', false, null, null],
            'ArrayItem->key' => [null, false, null, ' => '],
            'Expr_ArrowFunction->returnType' => [')', false, ': ', null],
            'Expr_Closure->returnType' => [')', false, ': ', null],
            'Expr_Ternary->if' => ['?', false, ' ', ' '],
            'Expr_Yield->key' => [\T_YIELD, false, null, ' => '],
            'Expr_Yield->value' => [\T_YIELD, false, ' ', null],
            'Param->type' => [null, false, null, ' '],
            'Param->default' => [null, false, ' = ', null],
            'Stmt_Break->num' => [\T_BREAK, false, ' ', null],
            'Stmt_Catch->var' => [null, false, ' ', null],
            'Stmt_ClassMethod->returnType' => [')', false, ': ', null],
            'Stmt_ClassConst->type' => [\T_CONST, false, ' ', null],
            'Stmt_Class->extends' => [null, false, ' extends ', null],
            'Stmt_Enum->scalarType' => [null, false, ' : ', null],
            'Stmt_EnumCase->expr' => [null, false, ' = ', null],
            self::ANON_CLASS . '->extends' => [null, false, ' extends ', null],
            'Stmt_Continue->num' => [\T_CONTINUE, false, ' ', null],
            'Stmt_Foreach->keyVar' => [\T_AS, false, null, ' => '],
            'Stmt_Function->returnType' => [')', false, ': ', null],
            'Stmt_If->else' => [null, false, ' ', null],
            'Stmt_Namespace->name' => [\T_NAMESPACE, false, ' ', null],
            'Stmt_Property->type' => [\T_VARIABLE, true, null, ' '],
            'PropertyItem->default' => [null, false, ' = ', null],
            'Stmt_Return->expr' => [\T_RETURN, false, ' ', null],
            'Stmt_StaticVar->default' => [null, false, ' = ', null],
            //'Stmt_TraitUseAdaptation_Alias->newName' => [T_AS, false, ' ', null], // TODO
            'Stmt_TryCatch->finally' => [null, false, ' ', null],

            // 'Expr_Exit->expr': Complicated due to optional ()
            // 'Stmt_Case->cond': Conversion from default to case
            // 'Stmt_Class->name': Unclear
            // 'Stmt_Declare->stmts': Not a proper node
            // 'Stmt_TraitUseAdaptation_Alias->newModifier': Not a proper node
        ];
    }

    protected function initializeListInsertionMap(): void {
        if (isset($this->listInsertionMap)) {
            return;
        }

        $this->listInsertionMap = [
            // special
            //'Expr_ShellExec->parts' => '', // TODO These need to be treated more carefully
            //'Scalar_InterpolatedString->parts' => '',
            Stmt\Catch_::class . '->types' => '|',
            UnionType::class . '->types' => '|',
            IntersectionType::class . '->types' => '&',
            Stmt\If_::class . '->elseifs' => ' ',
            Stmt\TryCatch::class . '->catches' => ' ',

            // comma-separated lists
            Expr\Array_::class . '->items' => ', ',
            Expr\ArrowFunction::class . '->params' => ', ',
            Expr\Closure::class . '->params' => ', ',
            Expr\Closure::class . '->uses' => ', ',
            Expr\FuncCall::class . '->args' => ', ',
            Expr\Isset_::class . '->vars' => ', ',
            Expr\List_::class . '->items' => ', ',
            Expr\MethodCall::class . '->args' => ', ',
            Expr\NullsafeMethodCall::class . '->args' => ', ',
            Expr\New_::class . '->args' => ', ',
            self::ANON_CLASS . '->args' => ', ',
            Expr\StaticCall::class . '->args' => ', ',
            Stmt\ClassConst::class . '->consts' => ', ',
            Stmt\ClassMethod::class . '->params' => ', ',
            Stmt\Class_::class . '->implements' => ', ',
            Stmt\Enum_::class . '->implements' => ', ',
            self::ANON_CLASS . '->implements' => ', ',
            Stmt\Const_::class . '->consts' => ', ',
            Stmt\Declare_::class . '->declares' => ', ',
            Stmt\Echo_::class . '->exprs' => ', ',
            Stmt\For_::class . '->init' => ', ',
            Stmt\For_::class . '->cond' => ', ',
            Stmt\For_::class . '->loop' => ', ',
            Stmt\Function_::class . '->params' => ', ',
            Stmt\Global_::class . '->vars' => ', ',
            Stmt\GroupUse::class . '->uses' => ', ',
            Stmt\Interface_::class . '->extends' => ', ',
            Expr\Match_::class . '->arms' => ', ',
            Stmt\Property::class . '->props' => ', ',
            Stmt\StaticVar::class . '->vars' => ', ',
            Stmt\TraitUse::class . '->traits' => ', ',
            Stmt\TraitUseAdaptation\Precedence::class . '->insteadof' => ', ',
            Stmt\Unset_::class .  '->vars' => ', ',
            Stmt\UseUse::class . '->uses' => ', ',
            MatchArm::class . '->conds' => ', ',
            AttributeGroup::class . '->attrs' => ', ',
            PropertyHook::class . '->params' => ', ',

            // statement lists
            Expr\Closure::class . '->stmts' => "\n",
            Stmt\Case_::class . '->stmts' => "\n",
            Stmt\Catch_::class . '->stmts' => "\n",
            Stmt\Class_::class . '->stmts' => "\n",
            Stmt\Enum_::class . '->stmts' => "\n",
            self::ANON_CLASS . '->stmts' => "\n",
            Stmt\Interface_::class . '->stmts' => "\n",
            Stmt\Trait_::class . '->stmts' => "\n",
            Stmt\ClassMethod::class . '->stmts' => "\n",
            Stmt\Declare_::class . '->stmts' => "\n",
            Stmt\Do_::class . '->stmts' => "\n",
            Stmt\ElseIf_::class . '->stmts' => "\n",
            Stmt\Else_::class . '->stmts' => "\n",
            Stmt\Finally_::class . '->stmts' => "\n",
            Stmt\Foreach_::class . '->stmts' => "\n",
            Stmt\For_::class . '->stmts' => "\n",
            Stmt\Function_::class . '->stmts' => "\n",
            Stmt\If_::class . '->stmts' => "\n",
            Stmt\Namespace_::class . '->stmts' => "\n",
            Stmt\Block::class . '->stmts' => "\n",

            // Attribute groups
            Stmt\Class_::class . '->attrGroups' => "\n",
            Stmt\Enum_::class . '->attrGroups' => "\n",
            Stmt\EnumCase::class . '->attrGroups' => "\n",
            Stmt\Interface_::class . '->attrGroups' => "\n",
            Stmt\Trait_::class . '->attrGroups' => "\n",
            Stmt\Function_::class . '->attrGroups' => "\n",
            Stmt\ClassMethod::class . '->attrGroups' => "\n",
            Stmt\ClassConst::class . '->attrGroups' => "\n",
            Stmt\Property::class . '->attrGroups' => "\n",
            self::ANON_CLASS . '->attrGroups' => ' ',
            Expr\Closure::class . '->attrGroups' => ' ',
            Expr\ArrowFunction::class . '->attrGroups' => ' ',
            Param::class . '->attrGroups' => ' ',
            PropertyHook::class . '->attrGroups' => ' ',

            Stmt\Switch_::class . '->cases' => "\n",
            Stmt\TraitUse::class . '->adaptations' => "\n",
            Stmt\TryCatch::class . '->stmts' => "\n",
            Stmt\While_::class . '->stmts' => "\n",
            PropertyHook::class . '->body' => "\n",
            Stmt\Property::class . '->hooks' => "\n",
            Param::class . '->hooks' => "\n",

            // dummy for top-level context
            'File->stmts' => "\n",
        ];
    }

    protected function initializeEmptyListInsertionMap(): void {
        if (isset($this->emptyListInsertionMap)) {
            return;
        }

        // TODO Insertion into empty statement lists.

        // [$find, $extraLeft, $extraRight]
        $this->emptyListInsertionMap = [
            Expr\ArrowFunction::class . '->params' => ['(', '', ''],
            Expr\Closure::class . '->uses' => [')', ' use (', ')'],
            Expr\Closure::class . '->params' => ['(', '', ''],
            Expr\FuncCall::class . '->args' => ['(', '', ''],
            Expr\MethodCall::class . '->args' => ['(', '', ''],
            Expr\NullsafeMethodCall::class . '->args' => ['(', '', ''],
            Expr\New_::class . '->args' => ['(', '', ''],
            self::ANON_CLASS . '->args' => ['(', '', ''],
            self::ANON_CLASS . '->implements' => [null, ' implements ', ''],
            Expr\StaticCall::class . '->args' => ['(', '', ''],
            Stmt\Class_::class . '->implements' => [null, ' implements ', ''],
            Stmt\Enum_::class . '->implements' => [null, ' implements ', ''],
            Stmt\ClassMethod::class . '->params' => ['(', '', ''],
            Stmt\Interface_::class . '->extends' => [null, ' extends ', ''],
            Stmt\Function_::class . '->params' => ['(', '', ''],
            Stmt\Interface_::class . '->attrGroups' => [null, '', "\n"],
            Stmt\Class_::class . '->attrGroups' => [null, '', "\n"],
            Stmt\Enum_::class . '->attrGroups' => [null, '', "\n"],
            Stmt\EnumCase::class . '->attrGroups' => [null, '', "\n"],
            Stmt\ClassConst::class . '->attrGroups' => [null, '', "\n"],
            Stmt\ClassMethod::class . '->attrGroups' => [null, '', "\n"],
            Stmt\Function_::class . '->attrGroups' => [null, '', "\n"],
            Stmt\Property::class . '->attrGroups' => [null, '', "\n"],
            Stmt\Trait_::class . '->attrGroups' => [null, '', "\n"],
            Expr\ArrowFunction::class . '->attrGroups' => [null, '', ' '],
            Expr\Closure::class . '->attrGroups' => [null, '', ' '],
            Stmt\Const_::class . '->attrGroups' => [null, '', "\n"],
            self::ANON_CLASS . '->attrGroups' => [\T_NEW, ' ', ''],

            /* These cannot be empty to start with:
             * Expr_Isset->vars
             * Stmt_Catch->types
             * Stmt_Const->consts
             * Stmt_ClassConst->consts
             * Stmt_Declare->declares
             * Stmt_Echo->exprs
             * Stmt_Global->vars
             * Stmt_GroupUse->uses
             * Stmt_Property->props
             * Stmt_StaticVar->vars
             * Stmt_TraitUse->traits
             * Stmt_TraitUseAdaptation_Precedence->insteadof
             * Stmt_Unset->vars
             * Stmt_Use->uses
             * UnionType->types
             */

            /* TODO
             * Stmt_If->elseifs
             * Stmt_TryCatch->catches
             * Expr_Array->items
             * Expr_List->items
             * Stmt_For->init
             * Stmt_For->cond
             * Stmt_For->loop
             */
        ];
    }

    protected function initializeModifierChangeMap(): void {
        if (isset($this->modifierChangeMap)) {
            return;
        }

        $this->modifierChangeMap = [
            Stmt\ClassConst::class . '->flags' => ['pModifiers', \T_WHITESPACE, \T_CONST],
            Stmt\ClassMethod::class . '->flags' => ['pModifiers', \T_WHITESPACE, \T_FUNCTION],
            Stmt\Class_::class . '->flags' => ['pModifiers', \T_WHITESPACE, \T_CLASS],
            Stmt\Property::class . '->flags' => ['pModifiers', \T_WHITESPACE, \T_VARIABLE],
            self::ANON_CLASS . '->flags' => ['pModifiers', \T_NEW, \T_CLASS],
            Param::class . '->flags' => ['pModifiers', \T_WHITESPACE, \T_VARIABLE],
            PropertyHook::class . '->flags' => ['pModifiers', \T_WHITESPACE, \T_STRING],
            Expr\Closure::class . '->static' => ['pStatic', \T_WHITESPACE, \T_FUNCTION],
            Expr\ArrowFunction::class . '->static' => ['pStatic', \T_WHITESPACE, \T_FN],
            //Stmt\TraitUseAdaptation\Alias::class . '->newModifier' => 0, // TODO
        ];

        // List of integer subnodes that are not modifiers:
        // Expr_Include->type
        // Stmt_GroupUse->type
        // Stmt_Use->type
        // UseItem->type
    }
}
