<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model\Config\Comment;

use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Magento\Config\Model\Config\CommentInterface;
use Magento\Framework\Escaper;

class ApiKey implements CommentInterface
{
    public function __construct(private readonly Escaper $escaper)
    {
    }

    public function getCommentText($elementValue): string
    {
        $url = $this->escaper->escapeUrl(rtrim(ProtectionPolicy::DEFAULT_ENDPOINT_URL, '/') . '/dashboard/');
        return (string)__(
            'Get your API key from the <a href="%1" target="_blank" rel="noopener noreferrer">'
            . 'HideGuard Dashboard</a>. Stored encrypted and used only by the server.',
            $url
        );
    }
}
