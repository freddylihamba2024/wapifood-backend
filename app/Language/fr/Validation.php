<?php

/**
 * Messages de validation en français (API WapiFood).
 * Les clés absentes retombent automatiquement sur la version anglaise de CI4.
 */
return [
    // Core Messages
    'noRuleSets'      => 'Aucun ensemble de règles défini dans la configuration de validation.',
    'ruleNotFound'    => '« {0} » n\'est pas une règle valide.',
    'groupNotFound'   => '« {0} » n\'est pas un groupe de règles de validation.',
    'groupNotArray'   => 'Le groupe de règles « {0} » doit être un tableau.',
    'invalidTemplate' => '« {0} » n\'est pas un modèle de validation valide.',

    // Rule Messages
    'alpha'                 => 'Le champ {field} ne doit contenir que des lettres.',
    'alpha_dash'            => 'Le champ {field} ne doit contenir que des lettres, chiffres, tirets et tirets bas.',
    'alpha_numeric'         => 'Le champ {field} ne doit contenir que des lettres et des chiffres.',
    'alpha_numeric_punct'   => 'Le champ {field} ne doit contenir que des lettres, chiffres, espaces et les caractères ~ ! # $ % & * - _ + = | : .',
    'alpha_numeric_space'   => 'Le champ {field} ne doit contenir que des lettres, chiffres et espaces.',
    'alpha_space'           => 'Le champ {field} ne doit contenir que des lettres et des espaces.',
    'decimal'               => 'Le champ {field} doit contenir un nombre décimal.',
    'differs'               => 'Le champ {field} doit être différent du champ {param}.',
    'equals'                => 'Le champ {field} doit valoir exactement : {param}.',
    'exact_length'          => 'Le champ {field} doit contenir exactement {param} caractères.',
    'field_exists'          => 'Le champ {field} doit être présent.',
    'greater_than'          => 'Le champ {field} doit contenir un nombre supérieur à {param}.',
    'greater_than_equal_to' => 'Le champ {field} doit contenir un nombre supérieur ou égal à {param}.',
    'hex'                   => 'Le champ {field} ne doit contenir que des caractères hexadécimaux.',
    'in_list'               => 'Le champ {field} doit être l\'une des valeurs suivantes : {param}.',
    'integer'               => 'Le champ {field} doit contenir un nombre entier.',
    'is_natural'            => 'Le champ {field} ne doit contenir que des chiffres.',
    'is_natural_no_zero'    => 'Le champ {field} ne doit contenir que des chiffres et être supérieur à zéro.',
    'is_not_unique'         => 'Le champ {field} doit correspondre à une valeur existante.',
    'is_unique'             => 'La valeur du champ {field} est déjà utilisée.',
    'less_than'             => 'Le champ {field} doit contenir un nombre inférieur à {param}.',
    'less_than_equal_to'    => 'Le champ {field} doit contenir un nombre inférieur ou égal à {param}.',
    'matches'               => 'Le champ {field} ne correspond pas au champ {param}.',
    'max_length'            => 'Le champ {field} ne doit pas dépasser {param} caractères.',
    'min_length'            => 'Le champ {field} doit contenir au moins {param} caractères.',
    'not_equals'            => 'Le champ {field} ne peut pas valoir : {param}.',
    'not_in_list'           => 'Le champ {field} ne doit pas être l\'une des valeurs suivantes : {param}.',
    'numeric'               => 'Le champ {field} ne doit contenir que des nombres.',
    'regex_match'           => 'Le format du champ {field} est incorrect.',
    'required'              => 'Le champ {field} est obligatoire.',
    'required_with'         => 'Le champ {field} est obligatoire lorsque {param} est renseigné.',
    'required_without'      => 'Le champ {field} est obligatoire lorsque {param} n\'est pas renseigné.',
    'string'                => 'Le champ {field} doit être une chaîne de caractères.',
    'timezone'              => 'Le champ {field} doit être un fuseau horaire valide.',
    'valid_base64'          => 'Le champ {field} doit être une chaîne base64 valide.',
    'valid_email'           => 'Le champ {field} doit contenir une adresse e-mail valide.',
    'valid_emails'          => 'Le champ {field} doit contenir des adresses e-mail valides.',
    'valid_ip'              => 'Le champ {field} doit contenir une adresse IP valide.',
    'valid_url'             => 'Le champ {field} doit contenir une URL valide.',
    'valid_url_strict'      => 'Le champ {field} doit contenir une URL valide.',
    'valid_date'            => 'Le champ {field} doit contenir une date valide.',
    'valid_json'            => 'Le champ {field} doit contenir un JSON valide.',

    // Règles WapiFood
    'wf_boolean'      => 'Le champ {field} doit être un booléen (true ou false).',
    'wf_money'        => 'Le champ {field} doit être un montant positif avec 2 décimales au maximum.',
    'wf_money_signed' => 'Le champ {field} doit être un montant avec 2 décimales au maximum.',
    'wf_phone'        => 'Le champ {field} doit contenir un numéro de téléphone valide.',
    'wf_int'          => 'Le champ {field} doit être un nombre entier.',
    'wf_list'         => 'Le champ {field} doit être une liste.',

    // Credit Cards
    'valid_cc_number' => '{field} ne semble pas être un numéro de carte valide.',

    // Files
    'uploaded' => '{field} n\'est pas un fichier téléversé valide.',
    'max_size' => 'Le fichier {field} est trop volumineux.',
    'is_image' => '{field} n\'est pas une image valide.',
    'mime_in'  => 'Le type de fichier de {field} n\'est pas autorisé.',
    'ext_in'   => 'L\'extension du fichier {field} n\'est pas autorisée.',
    'max_dims' => '{field} n\'est pas une image, ou ses dimensions sont trop grandes.',
    'min_dims' => '{field} n\'est pas une image, ou ses dimensions sont trop petites.',
];
