<?php
/**
 * Plugin Name: BAW Bundle Importer
 * Description: Importe des packs BAW en brouillons WooCommerce, produits simples ou variables.
 * Version: 0.4.0
 * Requires PHP: 8.0
 * Text Domain: baw-bundle-importer
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'admin_menu', function () {
    add_submenu_page( 'edit.php?post_type=product', 'Importer un lot BAW', 'Importer un lot BAW', 'manage_woocommerce', 'baw-bundle-importer', 'baw_bundle_page' );
} );

function baw_bundle_page() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
    echo '<div class="wrap"><h1>Importer un lot BAW</h1><p>Vérifie ou importe un ZIP BAW. Tous les produits parents sont créés ou restaurés en brouillon.</p>';
    if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'ZipArchive' ) ) {
        echo '<div class="notice notice-error"><p>WooCommerce et l’extension PHP ZipArchive sont nécessaires.</p></div></div>'; return;
    }
    if ( ! empty( $_POST['baw_bundle_import'] ) ) {
        check_admin_referer( 'baw_bundle_import' );
        foreach ( baw_bundle_import( $_FILES['baw_bundle_zip'] ?? array() ) as $line ) {
            echo '<div class="notice notice-info"><p>' . esc_html( $line ) . '</p></div>';
        }
    }
    echo '<form method="post" enctype="multipart/form-data">'; wp_nonce_field( 'baw_bundle_import' );
    echo '<p><input type="file" name="baw_bundle_zip" accept=".zip,application/zip" required></p>';
    echo '<p><label><input type="checkbox" name="baw_restore_existing" value="1"> Restaurer les SKU existants depuis ce pack</label></p>';
    echo '<p><label><input type="checkbox" name="baw_preview_only" value="1"> Vérifier le pack sans modifier les produits</label></p>';
    echo '<p><button class="button button-primary" name="baw_bundle_import" value="1">Vérifier ou importer</button></p></form></div>';
}

function baw_bundle_file( $zip, $name, $limit ) {
    if ( '' === $name ) { return null; }
    if ( ! preg_match( '/^[a-z0-9_.-]+$/i', $name ) ) { return false; }
    $stat = $zip->statName( $name );
    if ( ! $stat || $stat['size'] > $limit ) { return false; }
    $data = $zip->getFromName( $name );
    return is_string( $data ) ? $data : false;
}

function baw_bundle_import( $upload ) {
    $log = array();
    if ( empty( $upload['tmp_name'] ) || UPLOAD_ERR_OK !== (int) ( $upload['error'] ?? -1 ) || ! is_uploaded_file( $upload['tmp_name'] ) ) { return array( 'Téléversement invalide.' ); }
    if ( (int) $upload['size'] > 20 * 1024 * 1024 ) { return array( 'Le fichier ZIP dépasse 20 Mo.' ); }
    $zip = new ZipArchive();
    if ( true !== $zip->open( $upload['tmp_name'] ) ) { return array( 'ZIP illisible.' ); }
    $raw = baw_bundle_file( $zip, 'manifest.json', 2 * 1024 * 1024 );
    $manifest = is_string( $raw ) ? json_decode( $raw, true ) : null;
    $schema = (int) ( $manifest['schema_version'] ?? 0 );
    if ( ! is_array( $manifest ) || ! in_array( $schema, array( 1, 2 ), true ) || ! is_array( $manifest['products'] ?? null ) || ! count( $manifest['products'] ) || count( $manifest['products'] ) > 100 ) { $zip->close(); return array( 'Manifeste absent, invalide ou lot trop grand.' ); }
    $preview = ! empty( $_POST['baw_preview_only'] );
    $restore = ! empty( $_POST['baw_restore_existing'] );
    $seen = array();
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    foreach ( $manifest['products'] as $entry ) {
        if ( ! is_array( $entry ) ) { $log[] = 'Entrée ignorée : format invalide.'; continue; }
        $sku = sanitize_text_field( $entry['sku'] ?? '' );
        $name = sanitize_text_field( $entry['name'] ?? '' );
        $type = 2 === $schema ? sanitize_key( $entry['type'] ?? 'simple' ) : 'simple';
        $price = $entry['price'] ?? '';
        $image_name = is_string( $entry['image'] ?? '' ) ? $entry['image'] : '';
        $template_name = is_string( $entry['elementor_json'] ?? '' ) ? $entry['elementor_json'] : '';
        $variations = is_array( $entry['variations'] ?? null ) ? $entry['variations'] : array();
        $attributes = is_array( $entry['attributes'] ?? null ) ? $entry['attributes'] : array();
        if ( ! preg_match( '/^BAW-[A-Z0-9-]{3,80}$/', $sku ) || '' === $name || ! in_array( $type, array( 'simple', 'variable' ), true ) || ( 'simple' === $type && ( ! is_numeric( $price ) || (float) $price < 0 ) ) || count( $variations ) > 200 || count( $attributes ) > 3 ) { $log[] = ( $sku ?: 'Entrée' ) . ' : champs invalides.'; continue; }
        if ( isset( $seen[ $sku ] ) ) { $log[] = $sku . ' : SKU répété dans le pack.'; continue; }
        $seen[ $sku ] = true;

        $image_raw = null; $image_info = null;
        if ( '' !== $image_name ) {
            if ( ! preg_match( '/^[a-z0-9_-]+\.(jpe?g|png)$/i', $image_name ) ) { $log[] = $sku . ' : nom d’image invalide.'; continue; }
            $image_raw = baw_bundle_file( $zip, $image_name, 10 * 1024 * 1024 );
            $image_info = is_string( $image_raw ) ? @getimagesizefromstring( $image_raw ) : false;
            if ( ! $image_info || ! in_array( $image_info['mime'], array( 'image/jpeg', 'image/png' ), true ) ) { $log[] = $sku . ' : image absente ou invalide.'; continue; }
        }
        $template = null;
        if ( '' !== $template_name ) {
            if ( ! preg_match( '/^[a-z0-9_-]+\.json$/i', $template_name ) ) { $log[] = $sku . ' : nom de modèle invalide.'; continue; }
            $template_raw = baw_bundle_file( $zip, $template_name, 1024 * 1024 );
            $template = is_string( $template_raw ) ? json_decode( $template_raw, true ) : null;
            if ( ! is_array( $template ) || ! is_array( $template['content'] ?? null ) ) { $log[] = $sku . ' : modèle Elementor invalide.'; continue; }
        }
        if ( 'variable' === $type && ( ! count( $attributes ) || ! count( $variations ) ) ) { $log[] = $sku . ' : attributs ou variations absents.'; continue; }
        $variation_skus = array(); $valid_variations = true;
        foreach ( $variations as $variation ) {
            $vsku = sanitize_text_field( $variation['sku'] ?? '' );
            if ( ! preg_match( '/^BAW-[A-Z0-9-]{3,100}$/', $vsku ) || isset( $variation_skus[ $vsku ] ) || ! is_numeric( $variation['price'] ?? null ) || ! is_array( $variation['attributes'] ?? null ) ) { $valid_variations = false; break; }
            $variation_skus[ $vsku ] = true;
        }
        if ( ! $valid_variations ) { $log[] = $sku . ' : variation invalide ou répétée.'; continue; }
        $existing_id = wc_get_product_id_by_sku( $sku );
        if ( $existing_id && ! $restore && ! $preview ) { $log[] = $sku . ' : existe déjà, aucune modification.'; continue; }
        if ( $preview ) { $log[] = $sku . ' : ' . $type . ' valide (' . count( $variations ) . ' variation(s)).'; continue; }

        $old_product = $existing_id ? wc_get_product( $existing_id ) : null;
        if ( $existing_id && ( ! $old_product || ! current_user_can( 'edit_post', $existing_id ) ) ) { $log[] = $sku . ' : produit non modifiable.'; continue; }
        if ( $existing_id && ! $old_product->is_type( $type ) ) { $log[] = $sku . ' : type existant incompatible (' . $old_product->get_type() . ').'; continue; }
        $snapshot = $existing_id ? array( 'name' => $old_product->get_name(), 'status' => $old_product->get_status(), 'price' => $old_product->get_regular_price(), 'description' => $old_product->get_description(), 'short_description' => $old_product->get_short_description(), 'image_id' => $old_product->get_image_id() ) : null;
        $attachment_id = 0;
        if ( is_string( $image_raw ) ) {
            $ext = 'image/png' === $image_info['mime'] ? 'png' : 'jpg';
            $tmp = wp_tempnam( $sku . '.' . $ext );
            if ( ! $tmp || false === file_put_contents( $tmp, $image_raw ) ) { $log[] = $sku . ' : préparation de l’image impossible.'; continue; }
            $attachment_id = media_handle_sideload( array( 'name' => strtolower( $sku ) . '.' . $ext, 'tmp_name' => $tmp ), 0, $name );
            if ( is_wp_error( $attachment_id ) ) { @unlink( $tmp ); $log[] = $sku . ' : import de l’image impossible.'; continue; }
        }

        $product = $existing_id ? $old_product : ( 'variable' === $type ? new WC_Product_Variable() : new WC_Product_Simple() );
        $product->set_name( $name ); $product->set_sku( $sku ); $product->set_status( 'draft' );
        $product->set_catalog_visibility( 'hidden' );
        $product->set_description( wp_kses_post( $entry['description'] ?? '' ) );
        $product->set_short_description( wp_kses_post( $entry['short_description'] ?? '' ) );
        if ( 'simple' === $type ) { $product->set_regular_price( wc_format_decimal( $price ) ); }
        if ( $attachment_id ) { $product->set_image_id( $attachment_id ); }
        if ( 'variable' === $type ) {
            $wc_attributes = array();
            foreach ( $attributes as $position => $attribute ) {
                $attribute_name = sanitize_text_field( $attribute['name'] ?? '' );
                $values = array_values( array_filter( array_map( 'sanitize_text_field', is_array( $attribute['values'] ?? null ) ? $attribute['values'] : array() ) ) );
                if ( '' === $attribute_name || ! count( $values ) ) { continue; }
                $wc_attribute = new WC_Product_Attribute();
                $wc_attribute->set_id( 0 ); $wc_attribute->set_name( $attribute_name ); $wc_attribute->set_options( $values );
                $wc_attribute->set_position( $position ); $wc_attribute->set_visible( true ); $wc_attribute->set_variation( true );
                $wc_attributes[] = $wc_attribute;
            }
            $product->set_attributes( $wc_attributes );
        }
        try { $id = $product->save(); } catch ( Throwable $e ) { if ( $attachment_id ) { wp_delete_attachment( $attachment_id, true ); } $log[] = $sku . ' : enregistrement impossible.'; continue; }
        if ( $attachment_id ) { wp_update_post( array( 'ID' => $attachment_id, 'post_parent' => $id ) ); }
        if ( $existing_id ) { add_post_meta( $id, '_baw_import_backup_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_uuid4(), wp_json_encode( $snapshot ) ); }
        foreach ( is_array( $entry['source_meta'] ?? null ) ? $entry['source_meta'] : array() as $meta_key => $meta_value ) {
            if ( preg_match( '/^_baw_[a-z0-9_]{1,60}$/', $meta_key ) && ( is_scalar( $meta_value ) || null === $meta_value ) ) { update_post_meta( $id, $meta_key, sanitize_text_field( (string) $meta_value ) ); }
        }
        if ( 'variable' === $type ) {
            foreach ( $variations as $variation_data ) {
                $vsku = sanitize_text_field( $variation_data['sku'] );
                $vid = wc_get_product_id_by_sku( $vsku );
                $variation = $vid ? wc_get_product( $vid ) : new WC_Product_Variation();
                if ( $vid && ( ! $variation || (int) $variation->get_parent_id() !== (int) $id ) ) { $log[] = $vsku . ' : SKU déjà utilisé ailleurs.'; continue; }
                $variation->set_parent_id( $id ); $variation->set_sku( $vsku );
                $variation->set_status( 'publish' ); $variation->set_regular_price( wc_format_decimal( $variation_data['price'] ) );
                $variation->set_stock_status( 'outofstock' ); $variation->set_manage_stock( false );
                $variation_attributes = array();
                foreach ( $variation_data['attributes'] as $attribute_name => $attribute_value ) { $variation_attributes[ sanitize_title( $attribute_name ) ] = sanitize_text_field( $attribute_value ); }
                $variation->set_attributes( $variation_attributes );
                try { $variation->save(); } catch ( Throwable $e ) { $log[] = $vsku . ' : variation non enregistrée.'; }
            }
            WC_Product_Variable::sync( $id );
        }
        if ( is_array( $template ) && class_exists( '\\Elementor\\Plugin' ) ) {
            $template_id = $existing_id ? (int) get_post_meta( $id, '_baw_elementor_description_template_id', true ) : 0;
            if ( ! $template_id || 'elementor_library' !== get_post_type( $template_id ) ) { $template_id = wp_insert_post( array( 'post_type' => 'elementor_library', 'post_status' => 'draft', 'post_title' => $name . ' — description BAW' ), true ); }
            if ( ! is_wp_error( $template_id ) ) { update_post_meta( $template_id, '_elementor_edit_mode', 'builder' ); update_post_meta( $template_id, '_elementor_template_type', 'section' ); update_post_meta( $template_id, '_elementor_data', wp_slash( wp_json_encode( $template['content'] ) ) ); update_post_meta( $id, '_baw_elementor_description_template_id', $template_id ); }
        }
        $log[] = $sku . ' : brouillon ' . $type . ' importé (ID ' . $id . ', ' . count( $variations ) . ' variation(s)).';
    }
    $zip->close();
    return $log ?: array( 'Aucun produit importé.' );
}
