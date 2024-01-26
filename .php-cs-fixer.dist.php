<?php
/**
 * @see https://mlocati.github.io/php-cs-fixer-configurator/
 */

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->files()
    ->name('*.php')
    ->exclude(['var', 'vendor'])
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests');

$config = new PhpCsFixer\Config();

return $config->setRules([
    '@PSR12' => true,
    'array_syntax' => [
        'syntax' => 'short',
    ],
    'backtick_to_shell_exec' => true,
    'binary_operator_spaces' => true,
    'blank_line_before_statement' => [
        'statements' => ['return'],
    ],
    'braces' => [
        'allow_single_line_anonymous_class_with_empty_body' => true,
        'allow_single_line_closure' => true,
    ],
    'cast_spaces' => false,
    'class_attributes_separation' => false,
    'class_definition' => ['single_line' => true],
    'clean_namespace' => true,
    'concat_space' => [
        'spacing' => 'one',
    ],
    'echo_tag_syntax' => true,
    'empty_loop_body' => [
        'style' => 'braces',
    ],
    'empty_loop_condition' => true,
    'fully_qualified_strict_types' => true,
    'function_typehint_space' => true,
    'general_phpdoc_tag_rename' => [
        'replacements' => [
            'inheritDocs' => 'inheritDoc',
        ],
    ],
    'global_namespace_import' => [
        'import_classes' => true,
        'import_constants' => true,
        'import_functions' => true,
    ],
    'include' => true,
    'integer_literal_case' => true,
    'lambda_not_used_import' => true,
    'linebreak_after_opening_tag' => true,
    'magic_constant_casing' => true,
    'magic_method_casing' => true,
    'method_argument_space' => [
        'on_multiline' => 'ignore',
    ],
    'multiline_comment_opening_closing' => false,
    'native_function_casing' => true,
    'native_function_type_declaration_casing' => true,
    'no_alias_language_construct_call' => true,
    'no_alternative_syntax' => true,
    'no_blank_lines_after_phpdoc' => true,
    'no_closing_tag' => true,
    'no_empty_comment' => true,
    'no_empty_phpdoc' => true,
    'no_empty_statement' => true,
    'no_extra_blank_lines' => [
        'tokens' => [
            'case',
            'continue',
            'curly_brace_block',
            'default',
            'extra',
            'parenthesis_brace_block',
            'square_brace_block',
            'switch',
            'throw',
            'use',
        ],
    ],
    'no_leading_namespace_whitespace' => true,
    'no_mixed_echo_print' => true,
    'no_multiline_whitespace_around_double_arrow' => true,
    'no_short_bool_cast' => true,
    'no_singleline_whitespace_before_semicolons' => true,
    'no_spaces_around_offset' => true,
    'no_trailing_comma_in_list_call' => true,
    'no_trailing_comma_in_singleline_array' => true,
    'no_unneeded_control_parentheses' => [
        'statements' => [
            'break',
            'clone',
            'continue',
            'echo_print',
            'return',
            'switch_case',
            'yield',
            'yield_from',
        ],
    ],
    'no_unneeded_curly_braces' => true,
    'no_unset_cast' => true,
    'no_unused_imports' => true,
    'no_whitespace_before_comma_in_array' => true,
    'normalize_index_brace' => true,
    'object_operator_without_whitespace' => true,
    'ordered_imports' => [
        'imports_order' => ['class', 'function', 'const'],
        'sort_algorithm' => 'alpha',
    ],
    'ordered_types' => true,
    'php_unit_fqcn_annotation' => true,
    'php_unit_method_casing' => true,
    'phpdoc_align' => false,
    'phpdoc_annotation_without_dot' => true,
    'phpdoc_indent' => true,
    'phpdoc_inline_tag_normalizer' => true,
    'phpdoc_no_access' => true,
    'phpdoc_no_alias_tag' => true,
    'phpdoc_no_package' => true,
    'phpdoc_no_useless_inheritdoc' => true,
    'phpdoc_return_self_reference' => true,
    'phpdoc_scalar' => true,
    'phpdoc_separation' => false,
    'phpdoc_single_line_var_spacing' => true,
    'phpdoc_summary' => false,
    'phpdoc_tag_type' => [
        'tags' => ['inheritDoc' => 'inline'],
    ],
    'phpdoc_trim' => true,
    'phpdoc_trim_consecutive_blank_line_separation' => true,
    'phpdoc_types' => true,
    'phpdoc_types_order' => [
        'null_adjustment' => 'always_last',
        'sort_algorithm' => 'none',
    ],
    'phpdoc_var_without_name' => true,
    'protected_to_private' => true,
    'semicolon_after_instruction' => true,
    'single_class_element_per_statement' => true,
    'single_line_comment_style' => [
        'comment_types' => ['hash'],
    ],
    'single_line_throw' => false,
    'single_quote' => true,
    'single_space_after_construct' => true,
    'space_after_semicolon' => [
        'remove_in_empty_for_expressions' => true,
    ],
    'standardize_increment' => true,
    'standardize_not_equals' => true,
    'strict_param' => false,
    'switch_continue_to_break' => true,
    'trailing_comma_in_multiline' => [
        'elements' => ['arguments', 'arrays', 'match', 'parameters'],
    ],
    'trim_array_spaces' => true,
    'type_declaration_spaces' => [
        'elements' => ['function', 'property'],
    ],
    'types_spaces' => [
        'space' => 'none',
        'space_multiple_catch' => null,
    ],
    'unary_operator_spaces' => true,
    'whitespace_after_comma_in_array' => true,
    'yoda_style' => [
        'equal' => true,
        'identical' => true,
        'less_and_greater' => false,
        'always_move_variable' => true,
    ],
])->setFinder($finder);
