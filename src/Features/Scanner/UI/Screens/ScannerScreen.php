<?php

/**
 * Interface d'administration pour le Scanner de blocs Gutenberg
 *
 * Ce fichier contient l'interface utilisateur complète du Scanner,
 * incluant les contrôles d'analyse, l'affichage des résultats avec
 * pagination, et la gestion des requêtes AJAX. Il génère le HTML
 * côté serveur et coordonne avec l'interface JavaScript.
 *
 * @package     Diagnostic\Features\Scanner\UI\Screens
 * @author      Geoffroy Fontaine
 * @copyright   2025 Geoffroy Fontaine
 * @license     GPL-2.0+
 * @version     1.0.0
 * @since       1.0.0
 * @created     2025-09-11
 * @modified    2025-09-11
 *
 * @responsibilities:
 * - Interface d'administration du Scanner
 * - Gestion des requêtes AJAX d'analyse
 * - Génération HTML des résultats côté serveur
 * - Pagination des résultats (20 posts par page)
 * - Validation et sécurisation des données
 * - Intégration avec GutenbergValidator
 *
 * @dependencies:
 * - WordPress admin hooks et functions
 * - GutenbergValidator (logique d'analyse)
 * - Functions (utilitaires)
 * - Constants (configuration)
 * - WordPress nonces et sécurité
 *
 * @related_files:
 * - ../Core/GutenbergValidator.php (analyse)
 * - Feature.php (intégration et assets)
 * - ../Assets/js/scanner-interface.js (interface JS)
 * - ../Assets/css/scanner-interface.css (styles)
 *
 * @ajax_actions:
 * - run_scanner_validator (analyse complète)
 *
 * @features:
 * - Analyse de tous les posts et pages
 * - Pagination automatique des résultats
 * - Tri par types de problèmes
 * - Export des résultats
 */

namespace Diagnostic\Features\Scanner\UI\Screens;

use Diagnostic\Common\Constants;
use Diagnostic\Common\Functions;
use Diagnostic\Features\Scanner\Core\GutenbergValidator;

/**
 * Interface d'administration pour le Scanner
 * 
 * Classe responsable de l'affichage et de la gestion de l'interface
 * de diagnostic des blocs Gutenberg dans l'administration WordPress.
 */
class ScannerScreen
{
  /**
   * Rendre la page principale du scanner
   * 
   * Génère l'interface complète incluant contrôles, zones de résultats
   * et elements nécessaires à l'interaction JavaScript.
   * 
   * @return void
   */
  public static function render(): void
  {
    if (!current_user_can(Constants::CAP_USE_SCANNER)) {
      wp_die(__('Vous n\'avez pas les permissions suffisantes pour accéder à cette page.', Constants::TEXT_DOMAIN));
    }

    self::render_page();
  }

  /**
   * Rendre le contenu de la page
   */
  private static function render_page(): void
  {
?>
    <div class="diagnostic-scanner-wrap">
      <h1><?php echo esc_html__('Scanner Blocs', Constants::TEXT_DOMAIN); ?></h1>

      <!-- Navigation vers les sauvegardes -->
      <div class="scanner-actions">
        <a href="<?php echo esc_url(admin_url('admin.php?page=diagnostic_scanner_backups')); ?>"
          class="button button-secondary">
          💾 <?php echo esc_html__('Gérer les sauvegardes', Constants::TEXT_DOMAIN); ?>
        </a>
      </div>

      <!-- Debug mode removed in this build -->

      <!-- Actions -->
      <div class="diagnostic-scanner-card">
        <h2><?php echo esc_html__('Analyse complète', Constants::TEXT_DOMAIN); ?></h2>
        <div class="scanner-actions">
          <button id="run-scanner-validator" class="button button-primary button-hero">
            🔍 <?php echo esc_html__('Analyser tous les blocs (Articles + Pages)', Constants::TEXT_DOMAIN); ?>
          </button>
        </div>

        <div id="scanner-progress">
          <p>⏳ <?php echo esc_html__('Analyse en cours...', Constants::TEXT_DOMAIN); ?></p>
          <progress id="progress-bar"></progress>
        </div>
      </div>

      <!-- Résultats -->
      <?php
      $scan_data = get_transient('diagnostic_scanner_result');
      if ($scan_data && !empty($scan_data['html'])) {
        echo '<div id="scanner-results" class="diagnostic-scanner-card visible">';
        echo '<h2>📋 ' . esc_html__('Résultats de l\'analyse', Constants::TEXT_DOMAIN) . '</h2>';
        echo '<div id="scanner-results-content">' . $scan_data['html'] . '</div>';
        echo '</div>';
      } else {
        echo '<div id="scanner-results" class="diagnostic-scanner-card hidden">';
        echo '<h2>📋 ' . esc_html__('Résultats de l\'analyse', Constants::TEXT_DOMAIN) . '</h2>';
        echo '<div id="scanner-results-content"></div>';
        echo '</div>';
      }

      ?>
    </div>
  <?php
  }

  /**
   * Rendre la page de gestion des sauvegardes
   * 
   * @return void
   */
  public static function render_backups(): void
  {
    if (!current_user_can(Constants::CAP_USE_SCANNER)) {
      wp_die(__('Vous n\'avez pas les permissions suffisantes pour accéder à cette page.', Constants::TEXT_DOMAIN));
    }

    self::render_backups_page();
  }

  /**
   * Rendre le contenu de la page des sauvegardes
   */
  private static function render_backups_page(): void
  {
    // Importer la classe XMLBackupGenerator
    $backups = \Diagnostic\Features\Scanner\Core\XMLBackupGenerator::listBackups();
  ?>
    <div class="diagnostic-scanner-wrap">
      <h1><?php echo esc_html__('Sauvegardes Scanner', Constants::TEXT_DOMAIN); ?></h1>

      <div class="diagnostic-scanner-card">
        <h2><?php echo esc_html__('Gestion des sauvegardes', Constants::TEXT_DOMAIN); ?></h2>

        <p><?php echo esc_html__('Les sauvegardes XML sont générées automatiquement à chaque scan qui détecte des posts avec problèmes. Elles contiennent le contenu complet et les métadonnées des posts problématiques.', Constants::TEXT_DOMAIN); ?></p>

        <?php if (empty($backups)): ?>
          <div class="scanner-info-notice">
            <p><strong><?php echo esc_html__('Aucune sauvegarde disponible', Constants::TEXT_DOMAIN); ?></strong></p>
            <p><?php echo esc_html__('Les sauvegardes seront créées automatiquement lors du prochain scan qui détectera des problèmes.', Constants::TEXT_DOMAIN); ?></p>
          </div>
        <?php else: ?>
          <table class="wp-list-table widefat fixed striped">
            <thead>
              <tr>
                <th><?php echo esc_html__('Nom du fichier', Constants::TEXT_DOMAIN); ?></th>
                <th><?php echo esc_html__('Taille', Constants::TEXT_DOMAIN); ?></th>
                <th><?php echo esc_html__('Date de création', Constants::TEXT_DOMAIN); ?></th>
                <th><?php echo esc_html__('Actions', Constants::TEXT_DOMAIN); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($backups as $backup): ?>
                <tr>
                  <td><strong><?php echo esc_html($backup['filename']); ?></strong></td>
                  <td><?php echo esc_html(size_format($backup['size'])); ?></td>
                  <td><?php echo esc_html($backup['created_at']); ?></td>
                  <td>
                    <a href="<?php echo esc_url($backup['url']); ?>"
                      class="button button-secondary"
                      download="<?php echo esc_attr($backup['filename']); ?>"
                      target="_blank">
                      📥 <?php echo esc_html__('Télécharger', Constants::TEXT_DOMAIN); ?>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div class="scanner-section-spacing">
            <p><em><?php echo esc_html__('Les sauvegardes les plus anciennes sont automatiquement supprimées (maximum 10 sauvegardes conservées).', Constants::TEXT_DOMAIN); ?></em></p>
          </div>
        <?php endif; ?>

        <div class="scanner-section-divider">
          <h3><?php echo esc_html__('Informations techniques', Constants::TEXT_DOMAIN); ?></h3>
          <ul>
            <li><strong><?php echo esc_html__('Format:', Constants::TEXT_DOMAIN); ?></strong> XML compatible WordPress</li>
            <li><strong><?php echo esc_html__('Contenu:', Constants::TEXT_DOMAIN); ?></strong> Posts complets avec métadonnées et issues détectées</li>
            <li><strong><?php echo esc_html__('Sécurité:', Constants::TEXT_DOMAIN); ?></strong> Accès protégé par .htaccess</li>
            <li><strong><?php echo esc_html__('Rétention:', Constants::TEXT_DOMAIN); ?></strong> Maximum 10 sauvegardes, suppression automatique des plus anciennes</li>
          </ul>
        </div>
      </div>
    </div>
<?php
  }

  /**
   * Gestionnaire AJAX pour l'analyse
   */
  public static function ajax_handler(): void
  {
    // Vérification de sécurité
    if (!Functions::verify_nonce($_POST['nonce'] ?? '', Constants::NONCE_SCANNER)) {
      wp_send_json_error([
        'message' => __('Nonce de sécurité invalide', Constants::TEXT_DOMAIN),
        'method' => 'AJAX Security Check'
      ]);
      return;
    }

    if (!current_user_can(Constants::CAP_USE_SCANNER)) {
      wp_send_json_error([
        'message' => __('Permissions insuffisantes', Constants::TEXT_DOMAIN),
        'method' => 'Capability Check'
      ]);
      return;
    }

    try {
      // Récupérer la configuration
      $config_json = sanitize_text_field($_POST['config'] ?? '{}');
      $config = [];

      if (Functions::is_valid_json($config_json)) {
        $config = json_decode($config_json, true) ?: [];
      }

      // Valider la configuration
      $config = Functions::validate_config($config, [
        'limit' => Constants::DEFAULT_SCAN_LIMIT,
        'adminMode' => true,
        'verbose' => true
      ]);
      // Lancer l'analyse
      $results = self::run_analysis($config);

      // Vérifier si il y a une erreur
      if (isset($results['error']) && $results['error']) {
        wp_send_json_error([
          'message' => $results['message'],
          'details' => $results['details'] ?? '',
          'method' => $results['method'] ?? 'Unknown'
        ]);
        return;
      }

      // Générer le HTML des résultats côté serveur
      $results_html = self::generate_results_html($results);

      // Sauvegarder le résultat du scan dans un transient (12h)
      set_transient('diagnostic_scanner_result', [
        'results' => $results,
        'html' => $results_html
      ], 12 * HOUR_IN_SECONDS);

      // Déclencher l'action de fin d'analyse
      do_action(Constants::ACTION_SCANNER_COMPLETE, $results);

      wp_send_json_success([
        'results' => $results,
        'html' => $results_html
      ]);
    } catch (\Exception $e) {
      wp_send_json_error([
        'message' => __('Erreur lors de l\'analyse: ', Constants::TEXT_DOMAIN) . $e->getMessage(),
        'method' => 'Scanner Analysis',
        'details' => $e->getFile() . ' ligne ' . $e->getLine()
      ]);
    }
  }

  /**
   * Exécuter l'analyse
   */
  private static function run_analysis(array $config): array
  {
    try {
      if (!class_exists(GutenbergValidator::class)) {
        return [
          'error' => true,
          'message' => __('Classe GutenbergValidator non disponible', Constants::TEXT_DOMAIN)
        ];
      }

      $results = GutenbergValidator::run_analysis($config);

      if (!$results || !is_array($results)) {
        return [
          'error' => true,
          'message' => __('Le validateur a retourné des résultats invalides', Constants::TEXT_DOMAIN)
        ];
      }

      $results['method'] = 'Gutenberg Validator';
      return $results;
    } catch (\Exception $e) {
      return [
        'error' => true,
        'message' => $e->getMessage(),
        'details' => $e->getTraceAsString()
      ];
    }
  }

  /**
   * Générer le HTML des résultats
   */
  private static function generate_results_html(array $results): string
  {
    $summary_html = self::generate_summary_html($results);
    $backup_html = self::generate_backup_html($results);
    $issues_html = self::generate_issues_types_html($results);
    $details_html = self::generate_posts_details_html($results);

    return $summary_html . $backup_html . $issues_html . $details_html;
  }

  /**
   * Générer le HTML du résumé
   */
  private static function generate_summary_html(array $results): string
  {
    $total_posts = $results['totalPosts'] ?? 0;
    $posts_with_issues = $results['postsWithIssues'] ?? 0;
    $total_issues = $results['totalIssues'] ?? 0;

    $rate_html = '';
    if ($posts_with_issues > 0 && $total_posts > 0) {
      $rate = Functions::format_percentage($posts_with_issues, $total_posts);
      $rate_html = '<li><strong>' . esc_html__('Taux de problèmes:', Constants::TEXT_DOMAIN) . '</strong> ' . esc_html($rate) . '</li>';
    }

    return sprintf(
      '<div class="scanner-results-summary">
                <h3>📊 %s</h3>
                <ul>
                    <li><strong>%s:</strong> %d</li>
                    <li><strong>%s:</strong> <span class="stat-error">%d</span></li>
                    <li><strong>%s:</strong> <span class="stat-error">%d</span></li>
                    %s
                </ul>
            </div>',
      esc_html__('Résumé', Constants::TEXT_DOMAIN),
      esc_html__('Posts analysés', Constants::TEXT_DOMAIN),
      $total_posts,
      esc_html__('Posts avec problèmes', Constants::TEXT_DOMAIN),
      $posts_with_issues,
      esc_html__('Total des problèmes', Constants::TEXT_DOMAIN),
      $total_issues,
      $rate_html
    );
  }

  /**
   * Générer le HTML des informations de sauvegarde
   */
  private static function generate_backup_html(array $results): string
  {
    if (!isset($results['backup'])) {
      return '';
    }

    $backup = $results['backup'];

    if (!$backup['success']) {
      // En cas d'erreur de sauvegarde
      return sprintf(
        '<div class="scanner-backup-info backup-error">
          <h3>⚠️ %s</h3>
          <p><strong>%s:</strong> %s</p>
        </div>',
        esc_html__('Erreur de sauvegarde', Constants::TEXT_DOMAIN),
        esc_html__('Erreur', Constants::TEXT_DOMAIN),
        esc_html($backup['error'])
      );
    }

    if ($backup['posts_count'] === 0) {
      // Aucun post avec problème, pas de sauvegarde nécessaire
      return sprintf(
        '<div class="scanner-backup-info backup-none">
          <h3>✅ %s</h3>
          <p>%s</p>
        </div>',
        esc_html__('Aucune sauvegarde nécessaire', Constants::TEXT_DOMAIN),
        esc_html__('Félicitations ! Aucun post avec problème détecté.', Constants::TEXT_DOMAIN)
      );
    }

    // Sauvegarde générée avec succès
    $file_size = isset($backup['size']) ? size_format($backup['size']) : 'Inconnue';
    $created_at = isset($backup['created_at']) ? $backup['created_at'] : 'Inconnue';

    $download_button = '';
    if (isset($backup['url']) && !empty($backup['url'])) {
      $download_button = sprintf(
        '<p><a href="%s" class="button button-secondary" download="%s" target="_blank">
          📥 %s
        </a></p>',
        esc_url($backup['url']),
        esc_attr($backup['filename']),
        esc_html__('Télécharger la sauvegarde XML', Constants::TEXT_DOMAIN)
      );
    }

    return sprintf(
      '<div class="scanner-backup-info backup-success">
        <h3>💾 %s</h3>
        <ul>
          <li><strong>%s:</strong> %s</li>
          <li><strong>%s:</strong> %d</li>
          <li><strong>%s:</strong> %s</li>
          <li><strong>%s:</strong> %s</li>
        </ul>
        %s
        <p class="backup-note">
          💡 %s
        </p>
      </div>',
      esc_html__('Sauvegarde automatique générée', Constants::TEXT_DOMAIN),
      esc_html__('Fichier', Constants::TEXT_DOMAIN),
      esc_html($backup['filename']),
      esc_html__('Posts sauvegardés', Constants::TEXT_DOMAIN),
      $backup['posts_count'],
      esc_html__('Taille', Constants::TEXT_DOMAIN),
      esc_html($file_size),
      esc_html__('Créé le', Constants::TEXT_DOMAIN),
      esc_html($created_at),
      $download_button,
      esc_html__('Cette sauvegarde contient tous les posts avec problèmes détectés, incluant leur contenu complet et métadonnées.', Constants::TEXT_DOMAIN)
    );
  }

  /**
   * Générer le HTML des types de problèmes
   */
  private static function generate_issues_types_html(array $results): string
  {
    if (empty($results['issuesByType'])) {
      return '';
    }

    $rows = '';
    foreach ($results['issuesByType'] as $type => $count) {
      // Créer des lignes cliquables pour filtrer
      $display_name = self::get_issue_type_display_name($type);
      $rows .= sprintf(
        '<tr class="scanner-filter-row" data-issue-type="%s" style="cursor: pointer;" title="Cliquer pour filtrer par ce type">
          <td><strong>%s</strong> <span class="dashicons dashicons-filter" style="font-size: 14px; color: #0073aa;"></span></td>
          <td><span class="scanner-issue-count">%d</span></td>
        </tr>',
        esc_attr($type),
        Functions::esc_html($display_name),
        intval($count)
      );
    }

    return sprintf(
      '<div class="scanner-results-issues">
                <h3>🔍 %s <small style="color: #666; font-weight: normal;">(Cliquez sur un type pour filtrer)</small></h3>
                <table class="diagnostic-scanner-table scanner-issues-table" id="scanner-issues-table">
                    <thead>
                        <tr>
                            <th>%s</th>
                            <th>%s</th>
                        </tr>
                    </thead>
                    <tbody>%s</tbody>
                </table>
                <div id="filter-status" style="margin-top: 10px; font-style: italic; color: #666;"></div>
                <div style="margin-top: 10px;">
                  <button type="button" id="clear-all-filters" class="button" style="display: none;">
                    ❌ %s
                  </button>
                </div>
            </div>',
      esc_html__('Types de problèmes', Constants::TEXT_DOMAIN),
      esc_html__('Type de problème', Constants::TEXT_DOMAIN),
      esc_html__('Nombre', Constants::TEXT_DOMAIN),
      $rows,
      esc_html__('Effacer tous les filtres', Constants::TEXT_DOMAIN)
    );
  }

  /**
   * Obtenir le nom d'affichage d'un type d'issue
   */
  private static function get_issue_type_display_name(string $type): string
  {
    $display_names = [
      'BLOCK_RECOVERY_MODE' => 'Blocs en mode récupération',
      'BLOCK_CONVERT_TO_HTML' => 'Blocs à convertir en HTML',
      'CREATE_BLOCK_UNREGISTERED' => 'Blocs non enregistrés',
      'BLOCK_MISSING' => 'Blocs manquants',
      'BLOCK_ERROR' => 'Blocs avec erreurs'
    ];

    return $display_names[$type] ?? $type;
  }

  /**
   * Générer le HTML des détails des posts avec pagination
   */
  private static function generate_posts_details_html(array $results): string
  {
    if (empty($results['posts'])) {
      return '';
    }

    // Configuration de la pagination
    $posts_per_page = 20; // Augmenté de 10 à 20
    $total_posts = count($results['posts']);
    $total_pages = ceil($total_posts / $posts_per_page);
    $current_page = 1; // Page par défaut

    // Pour cette version, affichons toujours la première page
    $start_index = ($current_page - 1) * $posts_per_page;
    $posts_to_show = array_slice($results['posts'], $start_index, $posts_per_page);

    $rows = '';
    foreach ($posts_to_show as $post) {
      $issues = $post['issues'] ?? [];

      $issues_list = '';
      foreach ($issues as $issue) {
        if (isset($issue['type']) && isset($issue['message'])) {
          $issues_list .= sprintf(
            '<span class="scanner-issue-badge">%s: %s</span><br>',
            Functions::esc_html($issue['type']),
            Functions::esc_html($issue['message'])
          );
        }
      }

      $post_title = Functions::esc_html($post['title'] ?? __('Sans titre', Constants::TEXT_DOMAIN));
      $post_id = intval($post['id'] ?? 0);
      $edit_url = Functions::esc_url($post['editUrl'] ?? '');

      $post_link = !empty($edit_url) ?
        sprintf(
          '<a href="%s" target="_blank" class="scanner-post-edit-link"><strong>%s</strong> <span class="scanner-edit-icon">✏️</span></a>',
          $edit_url,
          $post_title
        ) :
        sprintf('<strong>%s</strong>', $post_title);

      $rows .= sprintf(
        '<tr data-post-id="%d" data-post-title="%s">
                    <td>%s</td>
                    <td>#%s</td>
                    <td>%d</td>
                    <td class="scanner-issues-cell">%s</td>
                </tr>',
        $post_id,
        esc_attr($post_title),
        $post_link,
        $post_id,
        count($issues),
        $issues_list
      );
    }

    // Créer les contrôles de pagination
    $pagination_html = '';
    if ($total_pages > 1) {
      $pagination_html = sprintf(
        '<div class="scanner-pagination">
          <div class="scanner-pagination-info">
            <span>%s</span>
          </div>
          <div class="scanner-pagination-controls">
            %s
            <span class="scanner-pagination-pages">%s</span>
            %s
          </div>
        </div>',
        sprintf(
          esc_html__('Affichage de %d-%d sur %d posts avec problèmes', Constants::TEXT_DOMAIN),
          $start_index + 1,
          min($start_index + $posts_per_page, $total_posts),
          $total_posts
        ),
        sprintf(
          '<button class="scanner-pagination-btn" data-page="%d" %s>‹ %s</button>',
          max(1, $current_page - 1),
          $current_page <= 1 ? 'disabled' : '',
          esc_html__('Précédent', Constants::TEXT_DOMAIN)
        ),
        sprintf(
          esc_html__('Page %d sur %d', Constants::TEXT_DOMAIN),
          $current_page,
          $total_pages
        ),
        sprintf(
          '<button class="scanner-pagination-btn" data-page="%d" %s>%s ›</button>',
          min($total_pages, $current_page + 1),
          $current_page >= $total_pages ? 'disabled' : '',
          esc_html__('Suivant', Constants::TEXT_DOMAIN)
        )
      );
    }

    return sprintf(
      '<div class="scanner-results-raw">
                <h3>📋 %s</h3>
                <table class="diagnostic-scanner-table" id="scanner-posts-table">
                    <thead>
                        <tr>
                            <th>%s</th>
                            <th>%s</th>
                            <th>%s</th>
                            <th>%s</th>
                        </tr>
                    </thead>
                    <tbody>%s</tbody>
                </table>
                %s
                <div class="scanner-pagination-data" style="display: none;" 
                     data-total-posts="%d" 
                     data-posts-per-page="%d" 
                     data-current-page="%d"
                     data-posts-data="%s">
                </div>
            </div>',
      sprintf(esc_html__('Détails des problèmes (%d posts)', Constants::TEXT_DOMAIN), $total_posts),
      esc_html__('Titre du post', Constants::TEXT_DOMAIN),
      esc_html__('ID', Constants::TEXT_DOMAIN),
      esc_html__('Nb problèmes', Constants::TEXT_DOMAIN),
      esc_html__('Détails des problèmes', Constants::TEXT_DOMAIN),
      $rows,
      $pagination_html,
      $total_posts,
      $posts_per_page,
      $current_page,
      esc_attr(base64_encode(json_encode($results['posts'])))
    );
  }
}
