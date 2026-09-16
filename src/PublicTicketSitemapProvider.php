<?php

declare(strict_types=1);

namespace LutionsWp;

final class PublicTicketSitemapProvider extends \WP_Sitemaps_Provider
{
    public function __construct()
    {
        $this->name = 'lutions';
    }

    // WordPress requires this provider method name.
    // phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    /** @return list<array{loc: string, lastmod?: string}> */
    public function get_url_list(mixed $page_num, mixed $subtype = ''): array
    {
        unset($subtype);

        return Plugin::publicTicketSitemapUrls(max(1, (int) $page_num));
    }

    public function get_max_num_pages(mixed $subtype = ''): int
    {
        unset($subtype);

        return Plugin::publicTicketSitemapPageCount();
    }
    // phpcs:enable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
}
