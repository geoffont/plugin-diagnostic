/**
 * Scanner JS Validation Module - Validation batch single-iframe
 *
 * Ce module charge UN SEUL iframe Gutenberg, puis envoie le contenu de chaque
 * post via postMessage pour validation avec wp.blocks.parse().
 *
 * Performance : ~200ms/post au lieu de ~15-30s (N iframes).
 * 100 posts : ~30s au lieu de ~30 minutes.
 *
 * @package     Company\Diagnostic\Features\Scanner
 * @author      Geoffroy Fontaine
 * @copyright   2025 Company
 * @license     GPL-2.0+
 * @version     3.0.0
 * @since       1.0.0
 * @created     2025-09-12
 * @modified    2025-12-02
 *
 * @workflow:
 * 1. Utilisateur clique sur "Analyser tous les blocs"
 * 2. Récupération de la liste des posts (AJAX)
 * 3. Filtrage par cache (posts non modifiés depuis le dernier scan)
 * 4. Création d'UN SEUL iframe Gutenberg (post-new.php?js_batch_validation=1)
 * 5. Attente du signal batch_validation_ready
 * 6. Récupération du contenu des posts par lots (REST /posts-content)
 * 7. Envoi séquentiel du contenu au iframe via postMessage
 * 8. Réception des résultats de chaque post via postMessage
 * 9. Envoi des résultats à BlockRecovery
 * 10. Affichage du tableau de résultats
 *
 * @dependencies:
 * - jQuery
 * - diagnosticScannerData (variables localisées)
 * - gutenberg-batch-validation.js (script exécuté dans l'iframe)
 */
(function($) {
  'use strict';

  // Constantes
  const IFRAME_BOOT_TIMEOUT = 60000; // 60s pour que Gutenberg boot dans l'iframe
  const POST_VALIDATION_TIMEOUT = 10000; // 10s par post (largement suffisant)
  const BATCH_SIZE = 20; // Nombre de posts récupérés via REST par lot
  const CACHE_KEY = 'diagnostic_scanner_cache';
  const CACHE_EXPIRY_DAYS = 30; // Durée de validité du cache

  /**
   * Système de logging conditionnel
   * Les logs ne s'affichent que si window.DEBUG_MODE est true
   */
  const log = {
    info: function(...args) {
      if (window.DEBUG_MODE) {
        console.log('[JS Validation]', ...args);
      }
    },
    warn: function(...args) {
      if (window.DEBUG_MODE) {
        console.warn('[JS Validation]', ...args);
      }
    },
    error: function(...args) {
      if (window.DEBUG_MODE) {
        console.error('[JS Validation]', ...args);
      }
    }
  };

  // État de la validation JS
  let jsValidationInProgress = false;
  let postsToValidate = [];
  let postsNeedingValidation = []; // Posts non présents dans le cache
  let validationResults = {};
  let completedPosts = 0; // Nombre de posts terminés (cache + validés)
  let cacheHits = 0; // Nombre de résultats trouvés dans le cache
  let batchIframe = null; // Référence à l'unique iframe
  let currentValidatingPostId = null; // Post en cours de validation
  let postValidationTimer = null; // Timer timeout par post

  /**
   * ======================================
   * SYSTÈME DE CACHE
   * ======================================
   */

  /**
   * Récupérer le cache depuis localStorage
   */
  function getCache() {
    try {
      const cacheData = localStorage.getItem(CACHE_KEY);
      if (!cacheData) {
        return {};
      }

      const cache = JSON.parse(cacheData);
      const now = Date.now();

      // Nettoyer les entrées expirées
      const cleanedCache = {};
      let hasExpired = false;

      for (const postId in cache) {
        const entry = cache[postId];
        const expiryTime = entry.timestamp + (CACHE_EXPIRY_DAYS * 24 * 60 * 60 * 1000);

        if (now < expiryTime) {
          cleanedCache[postId] = entry;
        } else {
          hasExpired = true;
        }
      }

      // Sauvegarder le cache nettoyé si des entrées ont expiré
      if (hasExpired) {
        localStorage.setItem(CACHE_KEY, JSON.stringify(cleanedCache));
      }

      return cleanedCache;
    } catch (e) {
      log.error('Erreur lors de la lecture du cache:', e);
      return {};
    }
  }

  /**
   * Sauvegarder un résultat dans le cache
   */
  function setCacheEntry(postId, postModified, result) {
    try {
      const cache = getCache();
      cache[postId] = {
        modified: postModified,
        result: result,
        timestamp: Date.now()
      };

      localStorage.setItem(CACHE_KEY, JSON.stringify(cache));
      log.info(`Cache mis à jour pour le post ${postId}`);
    } catch (e) {
      log.error('Erreur lors de la sauvegarde du cache:', e);
      // Si localStorage est plein, vider le cache et réessayer
      if (e.name === 'QuotaExceededError') {
        clearCache();
        log.warn('Cache vidé car localStorage est plein');
      }
    }
  }

  /**
   * Récupérer un résultat du cache si le post n'a pas été modifié
   */
  function getCacheEntry(postId, postModified) {
    try {
      const cache = getCache();
      const entry = cache[postId];

      if (!entry) {
        return null;
      }

      // Vérifier si le post a été modifié depuis la mise en cache
      if (entry.modified === postModified) {
        log.info(`✓ Cache HIT pour le post ${postId} (non modifié depuis ${postModified})`);
        return entry.result;
      }

      log.info(`✗ Cache MISS pour le post ${postId} (modifié: ${entry.modified} → ${postModified})`);
      return null;
    } catch (e) {
      log.error('Erreur lors de la lecture du cache:', e);
      return null;
    }
  }

  /**
   * Vider complètement le cache
   */
  function clearCache() {
    try {
      localStorage.removeItem(CACHE_KEY);
      log.info('Cache vidé avec succès');
    } catch (e) {
      log.error('Erreur lors du vidage du cache:', e);
    }
  }

  /**
   * Obtenir des statistiques sur le cache
   */
  function getCacheStats() {
    const cache = getCache();
    const entries = Object.keys(cache).length;
    const size = JSON.stringify(cache).length;

    return {
      entries: entries,
      size: size,
      sizeKB: (size / 1024).toFixed(2)
    };
  }

  /**
   * Initialiser la validation JavaScript
   */
  function initJsValidation() {
    // Écouter les messages postMessage de l'iframe batch
    window.addEventListener('message', handleValidationMessage);

    // Remplacer le comportement du bouton principal du scanner
    replaceMainScannerButton();
  }

  /**
   * Exposer les fonctions publiques pour l'utiliser depuis l'extérieur
   */
  window.ScannerJsValidation = {
    startValidation: startJsValidationForAllPosts,
    clearCache: function() {
      clearCache();
      const stats = getCacheStats();
      console.log(`✓ Cache vidé. Statistiques actuelles: ${stats.entries} entrées (${stats.sizeKB} KB)`);
      alert('Le cache du scanner a été vidé avec succès. Le prochain scan validera tous les posts.');
    },
    getCacheStats: function() {
      const stats = getCacheStats();
      console.log(`📦 Cache actuel: ${stats.entries} entrées (${stats.sizeKB} KB)`);
      return stats;
    }
  };

  /**
   * Remplacer le comportement du bouton principal du scanner
   */
  function replaceMainScannerButton() {
    const $runScannerBtn = $('#run-scanner-validator');

    if ($runScannerBtn.length === 0) {
      // Si le bouton n'existe pas encore, réessayer après un délai
      setTimeout(replaceMainScannerButton, 500);
      return;
    }

    // Désactiver le comportement par défaut et le remplacer par la validation JS
    $runScannerBtn.off('click');
    $runScannerBtn.on('click', function(e) {
      e.preventDefault();
      startJsValidationForAllPosts();
    });

    // Mettre à jour le texte du bouton
    $runScannerBtn.html('🔍 Analyser tous les blocs');

    log.info('Bouton principal remplacé avec validation JavaScript');
  }

  /**
   * Démarrer la validation JavaScript sur tous les posts
   */
  function startJsValidationForAllPosts() {
    if (jsValidationInProgress) {
      alert('Une validation est déjà en cours...');
      return;
    }

    log.info('Démarrage de l\'analyse de tous les posts...');

    // Afficher l'indicateur de chargement
    showScannerProgress();

    // Récupérer tous les posts via AJAX
    $.ajax({
      url: diagnosticScannerData.ajaxurl,
      type: 'POST',
      data: {
        action: 'get_all_posts_for_validation',
        nonce: diagnosticScannerData.nonce
      },
      success: function(response) {
        if (response.success && response.data && response.data.posts) {
          postsToValidate = response.data.posts;

          log.info('Posts récupérés:', postsToValidate.length);

          // Initialiser la validation
          jsValidationInProgress = true;
          completedPosts = 0;
          validationResults = {};
          cacheHits = 0;
          postsNeedingValidation = [];

          // Vérifier le cache pour chaque post
          const cacheStats = getCacheStats();
          log.info(`📦 Cache actuel: ${cacheStats.entries} entrées (${cacheStats.sizeKB} KB)`);

          postsToValidate.forEach(function(post) {
            const cachedResult = getCacheEntry(post.id, post.modified);
            if (cachedResult) {
              cacheHits++;
              completedPosts++;
              validationResults[post.id] = cachedResult;
            } else {
              postsNeedingValidation.push(post);
            }
          });

          log.info(`Cache: ${cacheHits} hits, ${postsNeedingValidation.length} posts à valider`);

          // Afficher la modal de progression
          showProgressModal();
          updateProgressModal(completedPosts, postsToValidate.length, 'Préparation...');

          // Si tous les posts sont en cache, terminer immédiatement
          if (postsNeedingValidation.length === 0) {
            log.info('Tous les posts sont en cache, finalisation...');
            finishJsValidation();
            return;
          }

          // Créer l'iframe batch unique
          createBatchIframe();
        } else {
          alert('Erreur lors de la récupération des posts: ' + (response.data?.message || 'Erreur inconnue'));
          hideScannerProgress();
        }
      },
      error: function(xhr, status, error) {
        log.error('Erreur AJAX:', error);
        alert('Erreur lors de la récupération des posts');
        hideScannerProgress();
      }
    });
  }

  /**
   * Afficher l'indicateur de progression du scanner
   */
  function showScannerProgress() {
    $('#scanner-progress').show();
    $('#run-scanner-validator').prop('disabled', true);
  }

  /**
   * Masquer l'indicateur de progression du scanner
   */
  function hideScannerProgress() {
    $('#scanner-progress').hide();
    $('#run-scanner-validator').prop('disabled', false);
  }

  /**
   * Créer l'iframe batch unique pour la validation
   */
  function createBatchIframe() {
    const adminUrl = diagnosticScannerData.ajaxurl.replace('/admin-ajax.php', '');
    const editorUrl = `${adminUrl}/post-new.php?js_batch_validation=1`;

    log.info('Création de l\'iframe batch:', editorUrl);

    updateProgressModal(completedPosts, postsToValidate.length, 'Chargement de Gutenberg...');

    const $iframe = $('<iframe>', {
      id: 'batch-validation-iframe',
      src: editorUrl,
      css: {
        position: 'absolute',
        left: '-9999px',
        width: '1px',
        height: '1px',
        border: 'none'
      }
    });

    $('body').append($iframe);
    batchIframe = $iframe[0];

    // Timeout si Gutenberg ne boot pas
    setTimeout(function() {
      if (batchIframe && postsNeedingValidation.length > 0 && !currentValidatingPostId) {
        log.error('Timeout: Gutenberg n\'a pas démarré dans l\'iframe');
        cleanupBatchIframe();

        // Fallback: marquer les posts restants comme erreur
        postsNeedingValidation.forEach(function(post) {
          if (!validationResults[post.id]) {
            validationResults[post.id] = {
              success: false,
              error: 'Timeout - Gutenberg n\'a pas pu démarrer'
            };
            completedPosts++;
          }
        });

        finishJsValidation();
      }
    }, IFRAME_BOOT_TIMEOUT);
  }

  /**
   * Nettoyer l'iframe batch
   */
  function cleanupBatchIframe() {
    if (batchIframe) {
      $(batchIframe).remove();
      batchIframe = null;
    }
    currentValidatingPostId = null;
    if (postValidationTimer) {
      clearTimeout(postValidationTimer);
      postValidationTimer = null;
    }
  }

  /**
   * Démarrer la validation séquentielle des posts
   * Appelée quand l'iframe batch signale qu'elle est prête
   */
  function startBatchValidation() {
    log.info(`Démarrage de la validation batch pour ${postsNeedingValidation.length} posts`);
    processBatchQueue(0);
  }

  /**
   * Traiter la file de posts par lots via REST API
   */
  function processBatchQueue(startIndex) {
    if (startIndex >= postsNeedingValidation.length) {
      // Tous les lots ont été traités
      cleanupBatchIframe();
      finishJsValidation();
      return;
    }

    const endIndex = Math.min(startIndex + BATCH_SIZE, postsNeedingValidation.length);
    const batch = postsNeedingValidation.slice(startIndex, endIndex);
    const batchIds = batch.map(function(p) { return p.id; });

    log.info(`Récupération du contenu des posts ${startIndex + 1}-${endIndex}/${postsNeedingValidation.length}`);

    // Récupérer le contenu des posts via REST
    $.ajax({
      url: diagnosticScannerData.restUrl + 'diagnostic/v1/posts-content',
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ ids: batchIds }),
      beforeSend: function(xhr) {
        xhr.setRequestHeader('X-WP-Nonce', diagnosticScannerData.restNonce);
      },
      success: function(response) {
        if (response && response.posts) {
          // Valider chaque post séquentiellement dans l'iframe
          processPostsSequentially(response.posts, 0, function() {
            // Lot terminé, passer au suivant
            processBatchQueue(endIndex);
          });
        } else {
          log.error('Réponse REST invalide');
          // Marquer les posts du lot comme erreurs
          batch.forEach(function(post) {
            validationResults[post.id] = {
              success: false,
              error: 'Erreur lors de la récupération du contenu'
            };
            completedPosts++;
          });
          updateProgressModal(completedPosts, postsToValidate.length, 'Erreur REST...');
          processBatchQueue(endIndex);
        }
      },
      error: function(xhr, status, error) {
        log.error('Erreur REST /posts-content:', error);
        batch.forEach(function(post) {
          validationResults[post.id] = {
            success: false,
            error: 'Erreur réseau lors de la récupération du contenu'
          };
          completedPosts++;
        });
        updateProgressModal(completedPosts, postsToValidate.length, 'Erreur réseau...');
        processBatchQueue(endIndex);
      }
    });
  }

  /**
   * Traiter les posts un par un dans l'iframe (séquentiellement)
   */
  function processPostsSequentially(posts, index, onBatchComplete) {
    if (index >= posts.length) {
      onBatchComplete();
      return;
    }

    const postData = posts[index];
    const postMeta = postsNeedingValidation.find(function(p) { return p.id == postData.id; });
    const postTitle = postMeta ? postMeta.title : 'Post #' + postData.id;

    currentValidatingPostId = postData.id;

    log.info(`Validation du post ${postData.id} (${postTitle})`);
    updateProgressModal(completedPosts, postsToValidate.length, postTitle);

    // Stocker le callback pour le prochain post
    window._batchNextCallback = function() {
      processPostsSequentially(posts, index + 1, onBatchComplete);
    };

    // Envoyer le contenu au iframe pour validation
    if (batchIframe && batchIframe.contentWindow) {
      batchIframe.contentWindow.postMessage({
        type: 'validate_post',
        postId: postData.id,
        content: postData.content
      }, window.location.origin);

      // Timeout de sécurité par post
      postValidationTimer = setTimeout(function() {
        if (currentValidatingPostId == postData.id) {
          log.warn(`Timeout pour le post ${postData.id}`);
          validationResults[postData.id] = {
            success: false,
            error: 'Timeout - la validation n\'a pas répondu'
          };
          completedPosts++;
          currentValidatingPostId = null;

          updateProgressModal(completedPosts, postsToValidate.length, 'Timeout...');

          if (window._batchNextCallback) {
            const cb = window._batchNextCallback;
            window._batchNextCallback = null;
            cb();
          }
        }
      }, POST_VALIDATION_TIMEOUT);
    } else {
      // Iframe disparue
      log.error('Iframe batch non disponible');
      validationResults[postData.id] = {
        success: false,
        error: 'Iframe de validation non disponible'
      };
      completedPosts++;
      processPostsSequentially(posts, index + 1, onBatchComplete);
    }
  }

  /**
   * Gérer les messages postMessage de l'iframe batch
   */
  function handleValidationMessage(event) {
    // Vérifier l'origine du message pour éviter les injections cross-origin
    if (event.origin !== window.location.origin) {
      return;
    }

    if (!event.data || !event.data.type) {
      return;
    }

    // Signal que l'iframe batch est prête
    if (event.data.type === 'batch_validation_ready') {
      log.info(`Iframe batch prête (${event.data.blockTypesCount} block types chargés)`);
      startBatchValidation();
      return;
    }

    // Résultat de validation d'un post
    if (event.data.type === 'gutenberg_validation_complete') {
      const data = event.data;
      const postId = data.postId;

      if (!postId) {
        log.error('Résultat de validation sans postId');
        return;
      }

      // Annuler le timeout pour ce post
      if (postValidationTimer) {
        clearTimeout(postValidationTimer);
        postValidationTimer = null;
      }

      // Sauvegarder les résultats
      validationResults[postId] = data;

      // Mettre en cache si succès
      if (data.success) {
        const postMeta = postsToValidate.find(function(p) { return p.id == postId; });
        if (postMeta && postMeta.modified) {
          setCacheEntry(postId, postMeta.modified, data);
        }
      }

      completedPosts++;
      currentValidatingPostId = null;

      log.info(`Post ${postId} validé. Progression: ${completedPosts}/${postsToValidate.length}`);

      const postMeta = postsToValidate.find(function(p) { return p.id == postId; });
      updateProgressModal(completedPosts, postsToValidate.length, postMeta ? postMeta.title : '');

      // Passer au post suivant dans la séquence
      if (window._batchNextCallback) {
        const cb = window._batchNextCallback;
        window._batchNextCallback = null;
        cb();
      }
    }
  }

  /**
   * Afficher une modal de progression
   */
  function showProgressModal() {
    const html = `
      <div id="js-validation-modal">
        <h2>Détection des blocs en récupération en cours...</h2>
        <p id="js-validation-progress">Préparation...</p>
        <div class="progress-container">
          <div id="js-validation-progress-bar"></div>
        </div>
        <p class="progress-note">Ne fermez pas cette fenêtre pendant la validation.</p>
      </div>
      <div id="js-validation-overlay"></div>
    `;

    $('body').append(html);
  }

  /**
   * Mettre à jour la modal de progression
   */
  function updateProgressModal(current, total, postTitle) {
    const percent = Math.round((current / total) * 100);
    $('#js-validation-progress').text(`Détection ${current}/${total}: ${postTitle}`);
    $('#js-validation-progress-bar').css('width', `${percent}%`);
  }

  /**
   * Terminer la validation JavaScript
   */
  function finishJsValidation() {
    jsValidationInProgress = false;

    // Afficher les statistiques du cache
    const cacheStats = getCacheStats();
    const totalPosts = postsToValidate.length;
    const validatedPosts = totalPosts - cacheHits;

    log.info(`
╔═══════════════════════════════════════════════════════
║ 📊 STATISTIQUES DU SCAN
╠═══════════════════════════════════════════════════════
║ Total posts:          ${totalPosts}
║ Posts depuis cache:   ${cacheHits} (${Math.round(cacheHits / totalPosts * 100)}%)
║ Posts validés:        ${validatedPosts} (${Math.round(validatedPosts / totalPosts * 100)}%)
║
║ 📦 Cache actuel:      ${cacheStats.entries} entrées (${cacheStats.sizeKB} KB)
╚═══════════════════════════════════════════════════════
    `);

    // Fermer la modal
    $('#js-validation-modal, #js-validation-overlay').remove();

    // Masquer le progress
    hideScannerProgress();

    // Envoyer les résultats à BlockRecovery
    sendResultsToBlockRecovery();

    // Générer et afficher le tableau HTML des résultats
    displayValidationResultsAsTable();

    // Afficher un message de performance si le cache a aidé
    if (cacheHits > 0) {
      showCachePerformanceMessage(cacheHits, totalPosts);
    }
  }

  /**
   * Envoyer les résultats de validation à BlockRecovery
   */
  function sendResultsToBlockRecovery() {
    log.info('Envoi des résultats à BlockRecovery...');

    $.ajax({
      url: diagnosticScannerData.ajaxurl,
      type: 'POST',
      data: {
        action: 'save_js_validation_results',
        nonce: diagnosticScannerData.nonce,
        results: JSON.stringify(validationResults)
      },
      success: function(response) {
        if (response.success) {
          log.info('Résultats enregistrés dans BlockRecovery:', response.data);

          // Afficher les informations de backup XML si disponibles
          if (response.data.backup) {
            showBackupInfo(response.data.backup);
          }

          // Afficher un lien vers BlockRecovery si des problèmes ont été détectés
          if (response.data.posts_with_issues > 0) {
            showBlockRecoveryLink(response.data.posts_with_issues);
          }
        } else {
          log.error('Erreur lors de l\'enregistrement:', response.data?.message);
        }
      },
      error: function(xhr, status, error) {
        log.error('Erreur AJAX lors de l\'enregistrement:', error);
      }
    });
  }

  /**
   * Afficher un message sur les gains de performance du cache
   */
  function showCachePerformanceMessage(cacheHits, totalPosts) {
    const percentage = Math.round(cacheHits / totalPosts * 100);
    const timeSaved = Math.round((cacheHits * 30) / 60); // Estimation: 30s par post

    const html = `
      <div class="scanner-cache-performance">
        <h3>⚡ Cache activé - Performance optimisée</h3>
        <p>
          <strong>${cacheHits} post(s)</strong> sur <strong>${totalPosts}</strong> (${percentage}%)
          ont été chargés depuis le cache, car ils n'ont pas été modifiés depuis le dernier scan.
        </p>
        <p class="time-saved">
          ⏱️ Temps économisé estimé: <strong>~${timeSaved} minute(s)</strong>
        </p>
      </div>
    `;

    $('#scanner-results-content').prepend(html);
  }

  /**
   * Afficher les informations de sauvegarde XML
   */
  function showBackupInfo(backup) {
    let html = '';

    if (!backup.success) {
      // Afficher l'erreur de backup
      html = `
        <div class="scanner-backup-info backup-error">
          <h3>⚠️ Erreur de sauvegarde</h3>
          <p><strong>Erreur:</strong> ${backup.error || 'Erreur inconnue'}</p>
        </div>
      `;
    } else if (backup.posts_count === 0) {
      // Aucun backup nécessaire
      html = `
        <div class="scanner-backup-info backup-none">
          <h3>✅ Aucune sauvegarde nécessaire</h3>
          <p>Félicitations ! Aucun post avec problème détecté.</p>
        </div>
      `;
    } else {
      // Backup généré avec succès
      const downloadButton = backup.url ? `
        <p>
          <a href="${backup.url}" class="button button-secondary" download="${backup.filename}" target="_blank">
            📥 Télécharger la sauvegarde XML
          </a>
        </p>
      ` : '';

      html = `
        <div class="scanner-backup-info backup-success">
          <h3>💾 Sauvegarde automatique générée</h3>
          <ul>
            <li><strong>Fichier:</strong> ${backup.filename || 'N/A'}</li>
            <li><strong>Posts sauvegardés:</strong> ${backup.posts_count || 0}</li>
            ${backup.size ? `<li><strong>Taille:</strong> ${formatBytes(backup.size)}</li>` : ''}
            ${backup.created_at ? `<li><strong>Créé le:</strong> ${backup.created_at}</li>` : ''}
          </ul>
          ${downloadButton}
          <p class="backup-note">
            💡 Cette sauvegarde contient tous les posts avec problèmes détectés, incluant leur contenu complet et métadonnées.
          </p>
        </div>
      `;
    }

    $('#scanner-results-content').prepend(html);
  }

  /**
   * Formater la taille des fichiers en octets
   */
  function formatBytes(bytes, decimals = 2) {
    if (bytes === 0) return '0 Bytes';

    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];

    const i = Math.floor(Math.log(bytes) / Math.log(k));

    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
  }

  /**
   * Afficher un lien vers la page BlockRecovery
   */
  function showBlockRecoveryLink(postsCount) {
    const blockRecoveryUrl = '/wp-admin/admin.php?page=diagnostic_block_recovery';
    const html = `
      <div class="scanner-block-recovery-notice">
        <div>
          <strong>🔧 Récupération disponible</strong>
          <p>
            ${postsCount} post(s) avec des blocs invalides ont été détectés.
            Utilisez la page Récupération de Blocs pour les corriger automatiquement.
          </p>
        </div>
        <a href="${blockRecoveryUrl}" class="button button-primary">
          Ouvrir Récupération de Blocs →
        </a>
      </div>
    `;

    $('#scanner-results-content').prepend(html);
  }


  /**
   * Afficher les résultats au format tableau HTML
   */
  function displayValidationResultsAsTable() {
    log.info('Génération du tableau HTML des résultats');

    // Analyser les résultats pour construire le tableau
    const postsWithIssues = [];

    for (const postId in validationResults) {
      const result = validationResults[postId];
      const post = postsToValidate.find(p => p.id == postId);

      if (!post) continue;

      if (result.success && result.invalidBlocks && result.invalidBlocks.length > 0) {
        postsWithIssues.push({
          id: postId,
          title: post.title,
          invalidBlocks: result.invalidBlocks
        });
      }
    }

    log.info('Posts avec blocs invalides:', postsWithIssues.length);

    // Générer le HTML du tableau
    let html = '';

    if (postsWithIssues.length === 0) {
      html = `
        <div class="scanner-results-summary">
          <div class="scanner-summary-success">
            <h3>✅ Analyse terminée - Aucun problème détecté</h3>
            <p>Tous les blocs sont valides dans les ${postsToValidate.length} post(s) analysé(s).</p>
          </div>
        </div>
      `;
    } else {
      // Résumé
      const totalInvalidBlocks = postsWithIssues.reduce((sum, p) => sum + p.invalidBlocks.length, 0);

      html += `
        <div class="scanner-results-summary">
          <div class="scanner-summary-warning">
            <h3>⚠️ Problèmes détectés</h3>
            <ul>
              <li><strong>${postsWithIssues.length}</strong> post(s) avec des blocs en recovery mode</li>
              <li><strong>${totalInvalidBlocks}</strong> bloc(s) invalide(s) au total</li>
              <li><strong>${postsToValidate.length}</strong> post(s) analysé(s)</li>
            </ul>
          </div>
        </div>
      `;

      // Tableau des posts avec problèmes
      html += `
        <div class="scanner-results-issues">
          <h3>Posts avec blocs invalides</h3>
          <table class="wp-list-table widefat fixed striped">
            <thead>
              <tr>
                <th>Post</th>
                <th>ID</th>
                <th>Nb de blocs invalides</th>
                <th>Issues</th>
              </tr>
            </thead>
            <tbody>
      `;

      // Lignes du tableau
      postsWithIssues.forEach(post => {
        const editUrl = `/wp-admin/post.php?post=${post.id}&action=edit`;
        const issuesList = post.invalidBlocks.map(block => {
          // Formater les messages de validation
          let validationMessages = '';

          if (block.validationIssues && block.validationIssues.length > 0) {
            // Debug: logger la structure des validationIssues
            log.info('validationIssues pour bloc', block.name, ':', block.validationIssues);

            const messages = block.validationIssues.map(issue => {
              // Si l'issue est une chaîne, la retourner directement
              if (typeof issue === 'string') {
                // Ignorer les chaînes qui sont "[object Object]"
                if (issue === '[object Object]') {
                  return null;
                }
                return issue;
              }

              // Si c'est un objet
              if (issue && typeof issue === 'object') {
                // Vérifier si message existe et n'est pas "[object Object]"
                if (issue.message && issue.message !== '[object Object]') {
                  return issue.message;
                }

                // Si le message est "[object Object]", ignorer et essayer d'extraire d'autres infos
                const parts = [];

                // Essayer d'extraire le code d'erreur
                if (issue.code && issue.code !== null) {
                  parts.push(`Code: ${issue.code}`);
                }

                // Essayer d'extraire des propriétés utiles
                if (issue.args !== undefined && issue.args !== null) {
                  try {
                    const argsStr = JSON.stringify(issue.args);
                    if (argsStr !== '{}' && argsStr !== '[]') {
                      parts.push(`Attributs: ${argsStr}`);
                    }
                  } catch (e) {
                    // Ignorer si la sérialisation échoue
                  }
                }

                if (issue.expected !== undefined) {
                  parts.push(`Attendu: ${issue.expected}`);
                }

                if (issue.actual !== undefined) {
                  parts.push(`Reçu: ${issue.actual}`);
                }

                // Si on a trouvé des informations utiles, les retourner
                if (parts.length > 0) {
                  return parts.join(', ');
                }

                // Sinon, retourner null pour indiquer qu'on n'a pas de détails
                return null;
              }

              // Cas par défaut
              return String(issue);
            }).filter(msg => msg && msg.trim() !== '' && msg !== '[object Object]'); // Filtrer les messages vides et [object Object]

            if (messages.length > 0) {
              validationMessages = `<br><small>${messages.join('<br>')}</small>`;
            } else {
              // Aucun message détaillé disponible, afficher un message générique utile
              validationMessages = `<br><small>Le contenu du bloc ne correspond pas à sa définition actuelle</small>`;
            }
          }

          return `<div class="scanner-issue-item">
            <span class="issue-badge">
              <strong>${block.name}</strong>
              <br><small>⚠️ Bloc en mode récupération</small>
              ${validationMessages}
            </span>
          </div>`;
        }).join('');

        html += `
          <tr data-post-id="${post.id}" data-post-title="${post.title.replace(/"/g, '&quot;')}">
            <td>
              <a href="${editUrl}" target="_blank" class="scanner-post-edit-link">
                <strong>${post.title}</strong>
                <span class="scanner-edit-icon">✏️</span>
              </a>
            </td>
            <td>#${post.id}</td>
            <td>${post.invalidBlocks.length}</td>
            <td class="scanner-issues-cell">${issuesList}</td>
          </tr>
        `;
      });

      html += `
            </tbody>
          </table>
        </div>
      `;
    }

    // Afficher le tableau
    $('#scanner-results').show();
    $('#scanner-results-content').html(html);

    // Log dans la console pour les détails
    log.info('Résultats détaillés:', validationResults);
  }


  // Initialiser au chargement de la page
  $(document).ready(function() {
    initJsValidation();
  });

})(jQuery);
