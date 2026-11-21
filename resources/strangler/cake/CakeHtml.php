<?php

namespace App\Http\Controllers\Legacy\Cake;

use function Cake\Core\h;

/**
 * Written by Booster's strangler (cakephp-to-laravel): HtmlHelper::link()
 * for a URL the move already built, with its confirm, escape and
 * escapeTitle options, printing CakePHP's markup.
 */
final class CakeHtml
{
    /**
     * @param array<string, mixed> $options
     */
    public static function link(string $title, string $url, array $options = []): string
    {
        $escapeTitle = true;
        if (isset($options['escapeTitle'])) {
            $escapeTitle = $options['escapeTitle'];
            unset($options['escapeTitle']);
        } elseif (isset($options['escape'])) {
            $escapeTitle = $options['escape'];
        }
        if ($escapeTitle === true) {
            $title = h($title);
        } elseif (is_string($escapeTitle)) {
            $title = htmlentities($title, ENT_QUOTES, $escapeTitle);
        }
        $confirm = $options['confirm'] ?? null;
        unset($options['confirm']);
        if ($confirm) {
            $options['data-confirm-message'] = $confirm;
            $options['onclick'] = 'if (confirm(this.dataset.confirmMessage)) { return true; } return false;';
        }

        return '<a href="' . $url . '"' . CakeForm::attributes($options) . '>' . $title . '</a>';
    }
}
