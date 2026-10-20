<?php

namespace Snapshot\Seo;

use WP_Sitemaps_Provider;

class SitemapExtra extends WP_Sitemaps_Provider
{
    public function __construct(private Sitemaps $sitemaps)
    {
        $this->name = 'extra';
        $this->object_type = 'extra';
    }

    public function get_url_list($page_num, $object_subtype = '')
    {
        $max = wp_sitemaps_get_max_urls($this->object_type);
        $urls = array_slice($this->sitemaps->extraUrls(), max(0, ((int) $page_num - 1) * $max), $max);

        return array_map(fn (array $url) => array_filter(['loc' => $url['loc'], 'lastmod' => $url['lastmod'] ?: null]), $urls);
    }

    public function get_max_num_pages($object_subtype = '')
    {
        $count = count($this->sitemaps->extraUrls());

        return $count ? (int) ceil($count / wp_sitemaps_get_max_urls($this->object_type)) : 0;
    }
}
