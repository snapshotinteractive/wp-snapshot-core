<?php

namespace Snapshot\Mcp\Tools;

use WP_Error;
use WP_Query;

class Media
{
    private const MAX_BYTES = 20 * MB_IN_BYTES;

    public static function summary(int $id): ?array
    {
        $post = get_post($id);

        if (! $post || $post->post_type !== 'attachment') {
            return null;
        }

        $meta = wp_get_attachment_metadata($id);

        return array_filter([
            'id' => $id,
            'title' => $post->post_title,
            'url' => (string) wp_get_attachment_url($id),
            'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            'caption' => $post->post_excerpt,
            'mime' => $post->post_mime_type,
            'width' => $meta['width'] ?? null,
            'height' => $meta['height'] ?? null,
            'attached_to' => $post->post_parent ?: null,
        ], fn ($value) => $value !== null);
    }

    public function list(array $input)
    {
        if (! current_user_can('upload_files')) {
            return new WP_Error('snapshot_mcp_denied', 'This account cannot browse the media library.');
        }

        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => (string) ($input['mime'] ?? ''),
            's' => (string) ($input['search'] ?? ''),
            'posts_per_page' => max(1, min(100, (int) ($input['per_page'] ?? 20))),
            'paged' => max(1, (int) ($input['page'] ?? 1)),
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        return [
            'items' => array_values(array_filter(array_map(fn ($post) => self::summary($post->ID), $query->posts))),
            'total' => (int) $query->found_posts,
            'pages' => (int) $query->max_num_pages,
        ];
    }

    public function upload(array $input)
    {
        if (! current_user_can('upload_files')) {
            return new WP_Error('snapshot_mcp_denied', 'This account cannot upload files.');
        }

        $attachTo = (int) ($input['attach_to'] ?? 0);

        if ($attachTo && ! current_user_can('edit_post', $attachTo)) {
            return new WP_Error('snapshot_mcp_denied', 'This account cannot edit the entry to attach the file to.');
        }

        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';

        $file = $this->fetch($input);

        if (is_wp_error($file)) {
            return $file;
        }

        $id = media_handle_sideload($file, $attachTo, isset($input['title']) ? sanitize_text_field((string) $input['title']) : null);

        if (is_wp_error($id)) {
            @unlink($file['tmp_name']);

            return $id;
        }

        $this->details($id, $input);

        return ['uploaded' => true] + self::summary($id);
    }

    public function update(array $input)
    {
        $id = (int) $input['id'];

        if (! self::summary($id)) {
            return new WP_Error('snapshot_mcp_not_found', 'No media item with that ID.');
        }

        if (! current_user_can('edit_post', $id)) {
            return new WP_Error('snapshot_mcp_denied', 'This account cannot edit that media item.');
        }

        $this->details($id, $input);

        return self::summary($id);
    }

    private function details(int $id, array $input): void
    {
        $post = array_filter([
            'post_title' => isset($input['title']) ? sanitize_text_field((string) $input['title']) : null,
            'post_excerpt' => isset($input['caption']) ? wp_kses_post((string) $input['caption']) : null,
            'post_content' => isset($input['description']) ? wp_kses_post((string) $input['description']) : null,
        ], fn ($value) => $value !== null);

        if ($post !== []) {
            Content::stored(fn () => wp_update_post(wp_slash($post + ['ID' => $id])));
        }

        if (isset($input['alt'])) {
            update_post_meta($id, '_wp_attachment_image_alt', wp_slash(sanitize_text_field((string) $input['alt'])));
        }
    }

    private function fetch(array $input)
    {
        if (! empty($input['url'])) {
            $url = esc_url_raw((string) $input['url'], ['http', 'https']);

            if ($url === '') {
                return new WP_Error('snapshot_mcp_url', 'The url must be an http or https address.');
            }

            $tmp = wp_tempnam('snapshot-mcp');
            $response = wp_safe_remote_get($url, ['timeout' => 60, 'stream' => true, 'filename' => $tmp, 'limit_response_size' => self::MAX_BYTES, 'redirection' => 3]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                @unlink($tmp);

                return is_wp_error($response) ? $response : new WP_Error('snapshot_mcp_download', sprintf('The address answered %d.', wp_remote_retrieve_response_code($response)));
            }

            if (filesize($tmp) >= self::MAX_BYTES || (int) wp_remote_retrieve_header($response, 'content-length') > self::MAX_BYTES) {
                @unlink($tmp);

                return new WP_Error('snapshot_mcp_size', sprintf('Files are limited to %s.', size_format(self::MAX_BYTES)));
            }

            $name = (string) ($input['filename'] ?? basename((string) wp_parse_url($url, PHP_URL_PATH)));
        } elseif (! empty($input['data'])) {
            if (strlen((string) $input['data']) > self::MAX_BYTES * 4 / 3 + 1024) {
                return new WP_Error('snapshot_mcp_size', sprintf('Files are limited to %s.', size_format(self::MAX_BYTES)));
            }

            $bytes = base64_decode(preg_replace('#^data:[^;]+;base64,#', '', (string) $input['data']), true);

            if ($bytes === false || $bytes === '') {
                return new WP_Error('snapshot_mcp_data', 'data must be base64 encoded file contents.');
            }

            $tmp = wp_tempnam('snapshot-mcp');
            file_put_contents($tmp, $bytes);
            $name = (string) ($input['filename'] ?? '');
        } else {
            return new WP_Error('snapshot_mcp_upload', 'Give either url, or data with filename.');
        }

        $name = sanitize_file_name($name);

        if ($name === '' || ! wp_check_filetype($name)['ext']) {
            @unlink($tmp);

            return new WP_Error('snapshot_mcp_filename', 'A filename with an allowed extension (such as photo.jpg) is required.');
        }

        if (filesize($tmp) > min(self::MAX_BYTES, wp_max_upload_size())) {
            @unlink($tmp);

            return new WP_Error('snapshot_mcp_size', sprintf('Files are limited to %s.', size_format(min(self::MAX_BYTES, wp_max_upload_size()))));
        }

        return ['name' => $name, 'tmp_name' => $tmp];
    }
}
