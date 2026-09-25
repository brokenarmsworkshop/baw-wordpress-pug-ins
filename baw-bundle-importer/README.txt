BAW Bundle Importer 0.4.0

Installer le ZIP du plug-in depuis Extensions > Ajouter > Téléverser une extension, puis l'activer. WooCommerce et PHP 8 avec ZipArchive sont nécessaires. Ensuite, Produits > Importer un lot BAW : sélectionner le ZIP de données distinct.

Un pack contient un manifest.json. Le schéma v1 reste compatible avec les produits simples. Le schéma v2 accepte les produits simples ou variables, jusqu'à trois attributs et 200 variations par produit. Les images et le JSON Elementor sont facultatifs en v2. Cocher « Vérifier le pack sans modifier les produits » avant l'importation.

Tous les produits parents sont créés en BROUILLON et cachés du catalogue. Les variations sont rattachées à leur parent et peuvent porter leur propre prix ; le pack de reprise Wix les place hors stock pour imposer une vérification humaine. Si une image incluse ne peut pas être importée, le produit concerné reste inchangé. Le modèle Elementor est enregistré dans la bibliothèque en brouillon si Elementor est actif.

Les SKU existants sont ignorés par défaut. Pour restaurer le contenu d'un produit à partir d'une ancienne version du pack, cocher explicitement « Restaurer les SKU existants ». Le plug-in sauvegarde dans les métadonnées l'état, le prix, les descriptions et l'image en place, puis importe le contenu du pack et remet le produit en brouillon. Ce mécanisme ne restaure pas le stock, les commandes, les poids ou dimensions de livraison. Faire une sauvegarde complète de WordPress et de sa base avant restauration.

Avant publication : confirmer les prix, la composition des lots, les dimensions, le poids emballé, le matériau, la finition, l'échelle, le stock, les images et le délai. Les SKU doivent rester stables entre versions.

Ce plug-in n'a pas été exécuté sur WordPress dans l'environnement de préparation. Effectuer un contrôle php -l et un essai sur une installation de test avant usage en production.
