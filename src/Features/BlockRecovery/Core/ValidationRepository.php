<?php

/**
 * Repository de validation des blocs - Gestion de persistance
 *
 * Ce fichier gère le stockage et la récupération des validations de blocs
 * dans wp_options. Il suit le pattern Repository pour abstraire la couche
 * de persistance des données de validation.
 *
 * @package     Company\Diagnostic\Features\BlockRecovery\Core
 * @author      Geoffroy Fontaine
 * @copyright   2025 Geoffroy Fontaine
 * @license     GPL-2.0+
 * @version     2.0.0
 * @since       2.0.0
 * @created     2025-10-21
 * @modified    2025-10-21
 *
 * @responsibilities:
 * - Stockage des validations dans wp_options
 * - Vérification du statut de validation d'un post
 * - Vérification de l'éligibilité à la récupération automatique (≥2 validations)
 * - Réinitialisation complète des validations
 * - Gestion de la clé composite post_id|block_name
 *
 * @dependencies:
 * - WordPress Options API (get_option, update_option)
 *
 * @related_files:
 * - ../Feature.php (configuration et coordination)
 * - BlockRecoveryService.php (service de récupération)
 * - ../UI/Screens/BlockRecoveryScreen.php (affichage des validations)
 * 
 * @storage:
 * Option: diagnostic_validated_blocks
 * Format: ['post_id|block_name' => ['post_id', 'block_name', 'validated_at']]
 */

namespace Company\Diagnostic\Features\BlockRecovery\Core;

class ValidationRepository
{
  private const OPTION_KEY = 'diagnostic_validated_blocks';

  /** @var array|null Cache des post IDs existants */
  private static $existing_post_ids_cache = null;

  /**
   * Vérifier en batch quels post_ids existent encore
   * Élimine le problème N+1 queries de get_post_status() individuel
   */
  private static function getExistingPostIds(array $post_ids): array
  {
    if (empty($post_ids)) {
      return [];
    }

    // Cache mémoire pour la durée de la requête
    $cache_key = md5(implode(',', $post_ids));
    if (self::$existing_post_ids_cache !== null && isset(self::$existing_post_ids_cache[$cache_key])) {
      return self::$existing_post_ids_cache[$cache_key];
    }

    global $wpdb;
    $ids = array_map('intval', array_unique($post_ids));
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are safe integers
    $results = $wpdb->get_col($wpdb->prepare(
      "SELECT ID FROM {$wpdb->posts} WHERE ID IN ($placeholders) AND post_status != 'trash'",
      ...$ids
    ));

    $existing = array_flip(array_map('intval', $results));

    if (self::$existing_post_ids_cache === null) {
      self::$existing_post_ids_cache = [];
    }
    self::$existing_post_ids_cache[$cache_key] = $existing;

    return $existing;
  }

  /**
   * Extraire tous les post_ids d'un tableau de validations
   */
  private static function extractPostIds(array $validated): array
  {
    $ids = [];
    foreach ($validated as $data) {
      if (isset($data['post_id'])) {
        $ids[] = (int) $data['post_id'];
      }
    }
    return array_unique($ids);
  }

  /**
   * Obtenir tous les blocs validés
   * Format: ['post_id|block_name' => ['post_id', 'block_name', 'validated_at']]
   */
  public function getAll(): array
  {
    return get_option(self::OPTION_KEY, []);
  }

  /**
   * Marquer un post/bloc comme validé
   */
  public function markAsValidated(int $post_id, string $block_name): bool
  {
    $validated = $this->getAll();
    $key = $this->makeKey($post_id, $block_name);

    $validated[$key] = [
      'post_id' => $post_id,
      'block_name' => $block_name,
      'validated_at' => current_time('mysql')
    ];

    return update_option(self::OPTION_KEY, $validated);
  }
  /**
   * Vérifier si un post/bloc est validé
   */
  public function isValidated(int $post_id, string $block_name): bool
  {
    $validated = $this->getAll();
    $key = $this->makeKey($post_id, $block_name);
    return isset($validated[$key]);
  }

  /**
   * Compter combien de posts différents ont été validés pour un bloc
   * Ne compte QUE les posts qui existent encore dans WordPress
   */
  public function countValidatedForBlock(string $block_name): int
  {
    $validated = $this->getAll();
    $post_ids = self::extractPostIds($validated);
    $existing = self::getExistingPostIds($post_ids);
    $count = 0;

    foreach ($validated as $data) {
      if (isset($data['block_name']) && $data['block_name'] === $block_name) {
        if (isset($data['post_id']) && isset($existing[(int) $data['post_id']])) {
          $count++;
        }
      }
    }

    return $count;
  }

  /**
   * Réinitialiser toutes les validations
   */
  public function resetAll(): bool
  {
    return delete_option(self::OPTION_KEY);
  }

  /**
   * Nettoyer les validations pour les posts qui n'existent plus
   * Retourne le nombre d'entrées supprimées
   */
  public function cleanupDeletedPosts(): int
  {
    $validated = $this->getAll();
    $post_ids = self::extractPostIds($validated);
    $existing = self::getExistingPostIds($post_ids);
    $cleaned = 0;

    foreach ($validated as $key => $data) {
      if (isset($data['post_id'])) {
        if (!isset($existing[(int) $data['post_id']])) {
          unset($validated[$key]);
          $cleaned++;
        }
      }
    }

    if ($cleaned > 0) {
      update_option(self::OPTION_KEY, $validated);
      // Invalider le cache
      self::$existing_post_ids_cache = null;
    }

    return $cleaned;
  }

  /**
   * Créer une clé unique pour un post/bloc
   */
  private function makeKey(int $post_id, string $block_name): string
  {
    return $post_id . '|' . $block_name;
  }

  /**
   * Vérifier si un bloc peut être récupéré automatiquement
   * (au moins 2 validations requises)
   */
  public function canAutoRecover(string $block_name): bool
  {
    return $this->countValidatedForBlock($block_name) >= 2;
  }

  /**
   * Compter le nombre total de posts validés (unique)
   * Ne compte QUE les posts qui existent encore dans WordPress
   * Utilisé pour les statistiques du dashboard
   */
  public static function countAllValidatedPosts(): int
  {
    $validated = get_option(self::OPTION_KEY, []);
    $post_ids = self::extractPostIds($validated);
    $existing = self::getExistingPostIds($post_ids);
    $count = 0;

    foreach ($validated as $data) {
      if (isset($data['post_id']) && isset($existing[(int) $data['post_id']])) {
        $count++;
      }
    }

    return $count;
  }

  /**
   * Obtenir la liste des noms de blocs qui ont au moins 2 validations
   * Ne compte QUE les posts qui existent encore dans WordPress
   * Ces blocs sont éligibles pour la récupération automatique
   */
  public static function getValidatedBlockNames(): array
  {
    $validated = get_option(self::OPTION_KEY, []);
    $post_ids = self::extractPostIds($validated);
    $existing = self::getExistingPostIds($post_ids);
    $counts = [];

    foreach ($validated as $data) {
      if (isset($data['block_name']) && isset($data['post_id']) && isset($existing[(int) $data['post_id']])) {
        $block_name = $data['block_name'];
        if (!isset($counts[$block_name])) {
          $counts[$block_name] = 0;
        }
        $counts[$block_name]++;
      }
    }

    // Filtrer les blocs avec au moins 2 validations
    $eligible_blocks = [];
    foreach ($counts as $block_name => $count) {
      if ($count >= 2) {
        $eligible_blocks[] = $block_name;
      }
    }

    return $eligible_blocks;
  }

  /**
   * Compter le nombre de récupérations disponibles
   * = Posts non validés pour les blocs qui ont au moins 2 validations
   */
  public static function countAvailableRecoveries(array $all_issues): int
  {
    $validated = get_option(self::OPTION_KEY, []);
    $eligible_blocks = self::getValidatedBlockNames();

    if (empty($eligible_blocks)) {
      return 0;
    }

    $count = 0;

    // Parcourir tous les issues
    foreach ($all_issues as $issue) {
      $block_name = $issue['block_name'] ?? '';
      $post_id = $issue['post_id'] ?? 0;

      // Vérifier si ce bloc est éligible (≥2 validations)
      if (!in_array($block_name, $eligible_blocks)) {
        continue;
      }

      // Vérifier si ce post n'est PAS validé
      $key = $post_id . '|' . $block_name;
      if (!isset($validated[$key])) {
        $count++;
      }
    }

    return $count;
  }
}
