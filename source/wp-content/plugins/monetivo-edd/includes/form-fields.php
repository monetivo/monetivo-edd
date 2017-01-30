<?php

return array(
    array(
        'id' => 'mvo_edd_settings',
        'name' => '<strong>' . __('Ustawienia Monetivo', 'monetivo') . '</strong>',
        'desc' => __('Konfiguracja bramki Monetivo', 'monetivo'),
        'type' => 'header'
    ),
    array(
        'id' => 'mvo_edd_title',
        'name' => __('Tytuł', 'monetivo'),
        'desc' => __( 'Tekst który zobaczą klienci podczas dokonywania zakupu', 'monetivo' ),
        'type' => 'text',
    ),
    array(
        'id' => 'mvo_edd_pos_id',
        'name' => __( 'Identyfikator POS', 'monetivo' ),
        'desc' => __('Dane znajdziesz w Panelu Merchanta', 'monetivo'),
        'type' => 'text',
    ),
    array(
        'id' => 'mvo_edd_app_token',
        'name' => __( 'Token aplikacji', 'monetivo' ),
        'desc' => __( 'Token aplikacji nadany w systemie Monetivo.', 'monetivo' ),
        'type' => 'text',
    ),
    array(
        'id' => 'mvo_edd_login',
        'name' => __( 'Login użytkownika', 'monetivo' ),
        'desc' => __( 'Login użytkownika integracji.', 'monetivo' ),
        'type' => 'text',
    ),
    array(
        'id' => 'mvo_edd_password',
        'name' => __( 'Hasło użytkownika', 'monetivo' ),
        'desc' => __( 'Hasło użytkownika integracji.', 'monetivo' ),
        'type' => 'password',
    )
);