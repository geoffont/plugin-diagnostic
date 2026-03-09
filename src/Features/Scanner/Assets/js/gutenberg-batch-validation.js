/**
 * Gutenberg Batch Validation Script - Validation par lot dans un seul iframe
 *
 * Ce script s'exécute dans un UNIQUE iframe Gutenberg et valide les posts
 * un par un en recevant leur contenu via postMessage, puis en utilisant
 * wp.blocks.parse() pour détecter les blocs invalides.
 *
 * Performance : ~200ms/post au lieu de ~15-30s/post (N iframes)
 *
 * @package     Company\Diagnostic\Features\Scanner
 * @version     1.0.0
 *
 * @workflow:
 * 1. Attendre que Gutenberg soit chargé (wp.blocks + block types)
 * 2. Signaler au parent que l'iframe est prête (batch_validation_ready)
 * 3. Écouter les messages validate_post du parent
 * 4. Pour chaque post : wp.blocks.parse(content) → vérifier isValid
 * 5. Envoyer les résultats via postMessage (gutenberg_validation_complete)
 */
(function() {
  'use strict';

  var readySignalSent = false;

  /**
   * Attendre que Gutenberg et les block types soient chargés
   */
  var waitForGutenberg = setInterval(function() {
    if (typeof wp !== 'undefined' &&
        typeof wp.blocks !== 'undefined' &&
        typeof wp.blocks.parse === 'function' &&
        typeof wp.blocks.getBlockTypes === 'function') {

      // Vérifier qu'au moins quelques block types sont enregistrés
      var blockTypes = wp.blocks.getBlockTypes();
      if (blockTypes && blockTypes.length > 0 && !readySignalSent) {
        clearInterval(waitForGutenberg);
        readySignalSent = true;
        signalReady(blockTypes.length);
        listenForValidationRequests();
      }
    }
  }, 100);

  /**
   * Signaler au parent que l'iframe est prête
   */
  function signalReady(blockTypesCount) {
    if (window.self === window.top) return;

    window.parent.postMessage({
      type: 'batch_validation_ready',
      blockTypesCount: blockTypesCount
    }, window.location.origin);
  }

  /**
   * Écouter les demandes de validation du parent
   */
  function listenForValidationRequests() {
    window.addEventListener('message', function(event) {
      // Sécurité : vérifier l'origine
      if (event.origin !== window.location.origin) return;
      if (!event.data || !event.data.type) return;

      if (event.data.type === 'validate_post') {
        validatePost(event.data.postId, event.data.content);
      }
    });
  }

  /**
   * Valider un post en parsant son contenu avec wp.blocks.parse()
   */
  function validatePost(postId, content) {
    try {
      // wp.blocks.parse() retourne des blocs avec isValid calculé
      var blocks = wp.blocks.parse(content || '');

      // Collecter les blocs invalides récursivement
      var invalidBlocks = [];
      findInvalidBlocks(blocks, invalidBlocks, []);

      // Envoyer les résultats au parent
      sendResult({
        type: 'gutenberg_validation_complete',
        postId: postId,
        success: true,
        invalidBlocks: invalidBlocks,
        totalBlocks: countBlocks(blocks)
      });

    } catch (error) {
      sendResult({
        type: 'gutenberg_validation_complete',
        postId: postId,
        success: false,
        error: error.message
      });
    }
  }

  /**
   * Trouver récursivement les blocs invalides
   */
  function findInvalidBlocks(blocks, invalidBlocks, path) {
    if (!blocks || !blocks.length) return;

    for (var i = 0; i < blocks.length; i++) {
      var block = blocks[i];
      var currentPath = path.concat([i]);

      // Ignorer les blocs freeform (pas de nom)
      if (!block.name) continue;

      // Vérifier la validité
      if (block.isValid === false) {
        invalidBlocks.push({
          name: block.name,
          clientId: block.clientId || null,
          attributes: serializeAttributes(block.attributes),
          path: currentPath,
          validationIssues: serializeValidationIssues(block.validationIssues)
        });
      }

      // Vérifier les blocs non enregistrés
      if (block.name && !wp.blocks.getBlockType(block.name)) {
        // Bloc non enregistré : considéré comme invalide
        var alreadyAdded = invalidBlocks.some(function(b) {
          return b.clientId === block.clientId;
        });
        if (!alreadyAdded) {
          invalidBlocks.push({
            name: block.name,
            clientId: block.clientId || null,
            attributes: serializeAttributes(block.attributes),
            path: currentPath,
            validationIssues: [{ message: 'Bloc non enregistré', code: 'unregistered_block' }]
          });
        }
      }

      // Récursion sur les innerBlocks
      if (block.innerBlocks && block.innerBlocks.length > 0) {
        findInvalidBlocks(block.innerBlocks, invalidBlocks, currentPath);
      }
    }
  }

  /**
   * Compter tous les blocs récursivement
   */
  function countBlocks(blocks) {
    var count = 0;
    if (!blocks) return count;
    for (var i = 0; i < blocks.length; i++) {
      count++;
      if (blocks[i].innerBlocks && blocks[i].innerBlocks.length > 0) {
        count += countBlocks(blocks[i].innerBlocks);
      }
    }
    return count;
  }

  /**
   * Sérialiser les attributs pour postMessage (éviter DataCloneError)
   */
  function serializeAttributes(attributes) {
    if (!attributes) return {};
    try {
      return JSON.parse(JSON.stringify(attributes));
    } catch (e) {
      return {};
    }
  }

  /**
   * Sérialiser les validationIssues pour postMessage
   */
  function serializeValidationIssues(issues) {
    if (!issues || !Array.isArray(issues)) return [];
    try {
      return issues.map(function(issue) {
        if (typeof issue === 'string') return issue;
        if (typeof issue === 'object') {
          return {
            message: issue.message || String(issue),
            code: issue.code || null
          };
        }
        return String(issue);
      });
    } catch (e) {
      return [];
    }
  }

  /**
   * Envoyer un résultat au parent via postMessage
   */
  function sendResult(data) {
    if (window.self === window.top) return;
    window.parent.postMessage(data, window.location.origin);
  }

})();
