<?php

namespace Snapshot\Seo;

class Identity
{
    private array $cache = [];

    public function __construct(private Settings $settings) {}

    public static function themeOption(string $name)
    {
        $value = get_option("options_{$name}", null);

        return $value === null || $value === false ? '' : $value;
    }

    public function name(): string
    {
        return $this->settings->string('org_name') ?: wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    }

    public function alternate(): string
    {
        return $this->settings->string('org_alternate');
    }

    public function description(): string
    {
        return $this->cache['description'] ??= $this->settings->string('org_description')
            ?: trim(wp_strip_all_tags((string) self::themeOption('footer_text')))
            ?: wp_specialchars_decode(get_bloginfo('description'), ENT_QUOTES);
    }

    public function email(): string
    {
        return sanitize_email($this->settings->string('org_email') ?: (string) self::themeOption('email'));
    }

    public function phone(): string
    {
        return $this->settings->string('org_phone') ?: (string) self::themeOption('phone_display');
    }

    public function address(): string
    {
        $address = $this->settings->string('org_address') ?: (string) self::themeOption('address');

        return trim(preg_replace('/\s*<br\s*\/?>\s*|\s*[\r\n]+\s*/i', ', ', wp_strip_all_tags($address, false)));
    }

    public function logoId(): int
    {
        return $this->settings->int('org_logo') ?: (int) self::themeOption('logo') ?: (int) self::themeOption('logo_light');
    }

    public function logo(): array
    {
        if (isset($this->cache['logo'])) {
            return $this->cache['logo'];
        }

        $id = $this->logoId();

        if ($id && ($image = wp_get_attachment_image_src($id, 'full'))) {
            return $this->cache['logo'] = ['url' => $image[0], 'width' => (int) $image[1], 'height' => (int) $image[2], 'id' => $id];
        }

        return $this->cache['logo'] = ['url' => '', 'width' => 0, 'height' => 0, 'id' => 0];
    }

    public function shareImageId(): int
    {
        return $this->settings->int('og_default_image') ?: (int) self::themeOption('og_image');
    }

    public function socialProfiles(): array
    {
        $profiles = [];

        foreach (array_keys(snap_social_networks()) as $network) {
            $url = esc_url_raw((string) self::themeOption($network));

            if ($url !== '') {
                $profiles[$network] = $url;
            }
        }

        return $profiles;
    }

    public function sameAs(): array
    {
        $extra = array_filter(array_map('esc_url_raw', $this->settings->lines('org_same_as')));

        return array_values(array_unique([...array_values($this->socialProfiles()), ...$extra]));
    }

    public function xHandle(): string
    {
        $handle = $this->settings->string('x_handle');

        if ($handle === '' && ($url = $this->socialProfiles()['x_twitter'] ?? '')) {
            $handle = (string) basename((string) wp_parse_url($url, PHP_URL_PATH));
        }

        $handle = ltrim($handle, '@');

        return $handle !== '' && preg_match('/^\w{1,15}$/', $handle) ? '@'.$handle : '';
    }

    public function sources(): array
    {
        return [
            'blogname' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'theme_logo' => $this->logo()['url'],
            'footer_text' => $this->description(),
            'email' => (string) self::themeOption('email'),
            'phone_display' => (string) self::themeOption('phone_display'),
            'address' => $this->address(),
            'og_image' => ($id = (int) self::themeOption('og_image')) ? (string) wp_get_attachment_image_url($id, 'medium') : '',
            'x_twitter' => $this->xHandle(),
            'org_description' => $this->description(),
        ];
    }
}
