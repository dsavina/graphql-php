<?php

declare(strict_types=1);

namespace GraphQL\Language\AST;

class FragmentSpreadNode extends Node implements SelectionNode
{
    public string $kind = NodeKind::FRAGMENT_SPREAD;

    /** @var NameNode */
    public $name;

    /** @var NodeList<DirectiveNode> */
    public $directives;
}
