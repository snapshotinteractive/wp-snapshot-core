<?php

namespace Snapshot\Mcp;

use WP_Error;

class Fields
{
    private const SKIP = ['tab', 'message', 'accordion'];

    private const CONTAINERS = ['repeater', 'flexible_content', 'group'];

    private const LISTS = ['checkbox', 'gallery', 'relationship', 'taxonomy'];

    public function available(): bool
    {
        return function_exists('acf_get_field_groups') && function_exists('acf_get_fields') && function_exists('update_field');
    }

    public function forPost(int $postId): array
    {
        return $this->available() ? $this->collect(acf_get_field_groups(['post_id' => $postId])) : [];
    }

    public function forPostType(string $type): array
    {
        return $this->available() ? $this->collect(acf_get_field_groups(['post_type' => $type])) : [];
    }

    public function forTerm(string $taxonomy): array
    {
        return $this->available() ? $this->collect(acf_get_field_groups(['taxonomy' => $taxonomy])) : [];
    }

    public function optionsPages(): array
    {
        if (! $this->available() || ! function_exists('acf_get_options_pages')) {
            return [];
        }

        $pages = [];

        foreach ((array) acf_get_options_pages() as $slug => $page) {
            $fields = $this->collect(acf_get_field_groups(['options_page' => $slug]));

            if ($fields === []) {
                continue;
            }

            $pages[$slug] = [
                'slug' => (string) $slug,
                'title' => (string) ($page['page_title'] ?? $slug),
                'post_id' => (string) ($page['post_id'] ?? 'options'),
                'capability' => (string) ($page['capability'] ?? 'edit_posts'),
                'fields' => $fields,
            ];
        }

        return $pages;
    }

    public function describe(array $fields): array
    {
        return array_values(array_map([$this, 'describeField'], $fields));
    }

    public function read($objectId, array $fields): array
    {
        $values = [];

        foreach ($fields as $name => $field) {
            $values[$name] = $this->named(get_field($field['key'], $objectId, false), $field);
        }

        return $values;
    }

    public function write($objectId, array $input, array $fields)
    {
        $prepared = $this->prepare($input, $fields);

        if (is_wp_error($prepared)) {
            return $prepared;
        }

        foreach ($prepared as $name => $value) {
            update_field($fields[$name]['key'], wp_slash($value), $objectId);
        }

        return array_keys($prepared);
    }

    public function prepare(array $input, array $fields)
    {
        $errors = new WP_Error;
        $prepared = [];

        foreach ($input as $name => $value) {
            $field = $fields[$name] ?? null;

            if ($field === null) {
                $errors->add('snapshot_mcp_unknown_field', sprintf('Unknown field "%s". Call describe-fields for the names this entry accepts.', $name));

                continue;
            }

            $prepared[$name] = $this->keyed($value, $field, $name, $errors);
        }

        return $errors->has_errors() ? $errors : $prepared;
    }

    private function collect(array $groups): array
    {
        $fields = [];

        foreach ($groups as $group) {
            if (isset($group['active']) && ! $group['active']) {
                continue;
            }

            foreach ((array) acf_get_fields($group) as $field) {
                if (! in_array($field['type'] ?? '', self::SKIP, true) && ($field['name'] ?? '') !== '') {
                    $fields[$field['name']] = $field + ['group' => (string) ($group['title'] ?? '')];
                }
            }
        }

        return $fields;
    }

    private function describeField(array $field): array
    {
        $out = array_filter([
            'name' => $field['name'],
            'label' => $field['label'] ?? '',
            'type' => $field['type'],
            'group' => $field['group'] ?? null,
            'instructions' => wp_strip_all_tags((string) ($field['instructions'] ?? '')),
            'required' => ! empty($field['required']),
            'choices' => ! empty($field['choices']) && is_array($field['choices']) ? $field['choices'] : null,
            'default' => ($field['default_value'] ?? '') !== '' ? $field['default_value'] : null,
            'multiple' => ! empty($field['multiple']) ?: null,
            'post_type' => ! empty($field['post_type']) ? (array) $field['post_type'] : null,
            'taxonomy' => ! empty($field['taxonomy']) ? $field['taxonomy'] : null,
            'max' => ! empty($field['max']) ? $field['max'] : null,
            'value' => $this->valueHint($field['type']),
        ], fn ($value) => $value !== null && $value !== '' && $value !== false);

        if ($field['type'] === 'flexible_content') {
            $out['layouts'] = array_values(array_map(fn ($layout) => [
                'name' => $layout['name'],
                'label' => $layout['label'] ?? $layout['name'],
                'fields' => $this->describe($this->subFields($layout['sub_fields'] ?? [])),
            ], $this->layouts($field)));
        } elseif (in_array($field['type'], ['repeater', 'group'], true)) {
            $out['fields'] = $this->describe($this->subFields($field['sub_fields'] ?? []));
        }

        return $out;
    }

    private function valueHint(string $type): ?string
    {
        return match ($type) {
            'image', 'file' => 'attachment ID',
            'gallery' => 'list of attachment IDs',
            'post_object', 'page_link' => 'post ID, or a list of post IDs when multiple',
            'relationship' => 'list of post IDs',
            'taxonomy' => 'term ID or list of term IDs',
            'user' => 'user ID',
            'link' => 'object with url, title and target ("" or "_blank")',
            'true_false' => '1 or 0',
            'flexible_content' => 'list of rows, each with "acf_fc_layout" set to a layout name plus that layout\'s field names',
            'repeater' => 'list of rows keyed by sub field name',
            'group' => 'object keyed by sub field name',
            'wysiwyg' => 'HTML',
            'color_picker' => 'hex colour such as #112233',
            'date_picker' => 'Ymd, for example 20261231',
            'date_time_picker' => 'Y-m-d H:i:s',
            'time_picker' => 'H:i:s',
            default => null,
        };
    }

    private function named($value, array $field)
    {
        $type = $field['type'] ?? '';

        if (! in_array($type, self::CONTAINERS, true) || ! is_array($value)) {
            return $value;
        }

        if ($type === 'group') {
            return $this->namedRow($value, $field['sub_fields'] ?? []);
        }

        if ($type === 'repeater') {
            return array_values(array_map(fn ($row) => is_array($row) ? $this->namedRow($row, $field['sub_fields'] ?? []) : $row, $value));
        }

        $layouts = $this->layouts($field);

        return array_values(array_map(function ($row) use ($layouts) {
            if (! is_array($row)) {
                return $row;
            }

            $layout = (string) ($row['acf_fc_layout'] ?? '');

            return ['acf_fc_layout' => $layout] + $this->namedRow($row, $layouts[$layout]['sub_fields'] ?? []);
        }, $value));
    }

    private function namedRow(array $row, array $subFields): array
    {
        $out = [];

        foreach ($this->subFields($subFields) as $name => $sub) {
            if (array_key_exists($sub['key'], $row)) {
                $out[$name] = $this->named($row[$sub['key']], $sub);
            } elseif (array_key_exists($name, $row)) {
                $out[$name] = $this->named($row[$name], $sub);
            }
        }

        return $out;
    }

    private function keyed($value, array $field, string $path, WP_Error $errors)
    {
        $type = $field['type'] ?? '';

        if (! in_array($type, self::CONTAINERS, true)) {
            return $this->scalar($value, $field, $path, $errors);
        }

        if ($value === null || $value === '' || $value === []) {
            return $type === 'group' ? [] : false;
        }

        if (! is_array($value)) {
            $errors->add('snapshot_mcp_field_shape', sprintf('"%s" is a %s field and needs %s.', $path, $type, $this->valueHint($type)));

            return null;
        }

        if ($type === 'group') {
            return $this->keyedRow($value, $field['sub_fields'] ?? [], $path, $errors);
        }

        if (! array_is_list($value)) {
            $errors->add('snapshot_mcp_field_shape', sprintf('"%s" needs a list of rows.', $path));

            return null;
        }

        $rows = [];

        if ($type === 'repeater') {
            foreach ($value as $i => $row) {
                if (! is_array($row)) {
                    $errors->add('snapshot_mcp_field_shape', sprintf('"%s[%d]" must be an object.', $path, $i));

                    continue;
                }

                $rows[] = $this->keyedRow($row, $field['sub_fields'] ?? [], "{$path}[{$i}]", $errors);
            }

            return $rows;
        }

        $layouts = $this->layouts($field);

        foreach ($value as $i => $row) {
            $layout = is_array($row) ? (string) ($row['acf_fc_layout'] ?? '') : '';

            if (! isset($layouts[$layout])) {
                $errors->add('snapshot_mcp_unknown_layout', sprintf('"%s[%d]" needs "acf_fc_layout" set to one of: %s.', $path, $i, implode(', ', array_keys($layouts))));

                continue;
            }

            unset($row['acf_fc_layout']);
            $rows[] = ['acf_fc_layout' => $layout] + $this->keyedRow($row, $layouts[$layout]['sub_fields'] ?? [], "{$path}[{$i}]", $errors);
        }

        return $rows;
    }

    private function keyedRow(array $row, array $subFields, string $path, WP_Error $errors): array
    {
        $subs = $this->subFields($subFields);
        $byKey = array_column($subs, null, 'key');
        $out = [];

        foreach ($row as $name => $value) {
            $sub = $subs[$name] ?? $byKey[$name] ?? null;

            if ($sub === null) {
                $errors->add('snapshot_mcp_unknown_field', sprintf('Unknown field "%s.%s". Known fields: %s.', $path, $name, implode(', ', array_keys($subs))));

                continue;
            }

            $out[$sub['key']] = $this->keyed($value, $sub, "{$path}.{$sub['name']}", $errors);
        }

        return $out;
    }

    private function scalar($value, array $field, string $path, WP_Error $errors)
    {
        $type = (string) ($field['type'] ?? '');

        if ($type === 'link') {
            return $this->link($value, $path, $errors);
        }

        $listable = in_array($type, self::LISTS, true) || (in_array($type, ['select', 'post_object', 'page_link', 'user'], true) && ! empty($field['multiple']));

        if (is_array($value) && ($type !== 'google_map' && (! $listable || ! array_is_list($value) || array_filter($value, fn ($item) => ! is_scalar($item)) !== []))) {
            $errors->add('snapshot_mcp_field_shape', sprintf('"%s" is a %s field and needs %s.', $path, $type, $this->valueHint($type) ?? ($listable ? 'a list of plain values' : 'a plain value')));

            return null;
        }

        if (is_object($value)) {
            $errors->add('snapshot_mcp_field_shape', sprintf('"%s" needs a plain value.', $path));

            return null;
        }

        $choices = is_array($field['choices'] ?? null) ? array_map('strval', array_keys($field['choices'])) : [];

        if ($choices !== [] && in_array($field['type'], ['select', 'radio', 'button_group', 'checkbox'], true) && $value !== null && $value !== '') {
            foreach ((array) $value as $choice) {
                if (! in_array((string) $choice, $choices, true)) {
                    $errors->add('snapshot_mcp_field_choice', sprintf('"%s" must be one of: %s. Got "%s".', $path, implode(', ', $choices), is_scalar($choice) ? $choice : gettype($choice)));
                }
            }
        }

        if ($field['type'] === 'true_false') {
            return $value ? 1 : 0;
        }

        return current_user_can('unfiltered_html') ? $value : $this->clean($value, $field['type']);
    }

    private function link($value, string $path, WP_Error $errors)
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        if (is_string($value)) {
            $value = ['url' => $value];
        }

        if (! is_array($value) || array_diff(array_keys($value), ['url', 'title', 'target']) !== [] || array_filter($value, fn ($item) => ! is_string($item)) !== []) {
            $errors->add('snapshot_mcp_field_shape', sprintf('"%s" is a link field and needs an object with string url, title and target.', $path));

            return null;
        }

        $target = (string) ($value['target'] ?? '');

        if (! in_array($target, ['', '_blank'], true)) {
            $errors->add('snapshot_mcp_field_shape', sprintf('"%s.target" must be "" or "_blank".', $path));

            return null;
        }

        return [
            'url' => esc_url_raw((string) ($value['url'] ?? '')),
            'title' => current_user_can('unfiltered_html') ? (string) ($value['title'] ?? '') : wp_kses_data((string) ($value['title'] ?? '')),
            'target' => $target,
        ];
    }

    private function clean($value, string $type, string $key = '')
    {
        if (is_array($value)) {
            foreach ($value as $k => $item) {
                $value[$k] = $this->clean($item, $type, (string) $k);
            }

            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        return $type === 'url' || ($type === 'link' && $key === 'url') ? esc_url_raw($value) : wp_kses_post($value);
    }

    private function layouts(array $field): array
    {
        $layouts = [];

        foreach ((array) ($field['layouts'] ?? []) as $layout) {
            if (! empty($layout['name'])) {
                $layouts[$layout['name']] = $layout;
            }
        }

        return $layouts;
    }

    private function subFields(array $subFields): array
    {
        $out = [];

        foreach ($subFields as $sub) {
            if (! in_array($sub['type'] ?? '', self::SKIP, true) && ($sub['name'] ?? '') !== '') {
                $out[$sub['name']] = $sub;
            }
        }

        return $out;
    }
}
