# BAW Bundle Importer

Source : `plugins/baw-bundle-importer/baw-bundle-importer.php`. Installer comme plug-in WordPress dans `wp-content/plugins/baw-bundle-importer/` puis activer. Prérequis : WooCommerce, PHP 8 et ZipArchive. Elementor est facultatif ; si actif, les modèles sont conservés comme références éditables.

La version 0.4.0 accepte le schéma v1 pour les produits simples et le schéma v2 pour les produits simples ou variables. Le mode « Vérifier le pack » ne modifie pas WooCommerce. Un produit variable peut avoir jusqu'à trois attributs et 200 variations. Les images et les modèles Elementor peuvent être omis dans le schéma v2, notamment pour une reprise de catalogue dont les médias ne sont pas disponibles.

L'import est limité aux comptes ayant `manage_woocommerce`, vérifie un nonce et accepte un ZIP de 20 Mo au plus. Il n'extrait pas librement l'archive et contrôle les fichiers référencés. Les produits parents sont créés en brouillon et cachés du catalogue. Le SKU empêche les doublons ; la restauration par SKU requiert une case explicite et sauvegarde les champs principaux remplacés. Le stock, le poids, les dimensions, les images absentes et la publication restent sous contrôle manuel.

## Vérification requise avant usage en production

Ce plug-in n'a pas encore été exécuté sur un WordPress de test. Faire une sauvegarde complète, tester le premier pack sur un environnement de préproduction ou avec des brouillons, contrôler l'apparence Astra/Elementor et le panier, puis seulement l'utiliser sur la boutique. L'absence d'un interpréteur PHP dans l'environnement de préparation a empêché le contrôle `php -l` ; effectuer ce contrôle sur le serveur avant installation.
