/**
 * akvarelatedcategories -- front-office click tracking for internal links (v1.2.0).
 *
 * The related-category block (renderBlock) and the inline description auto-links
 * (AkvarcDescriptionLinker) render every anchor with stable data attributes:
 *   data-akvarc-category-id  the target category id (int)
 *   data-akvarc-source       'related_block' | 'description_link'
 *   data-akvarc-variant      'category' | 'product' | 'cms' (page type it renders on)
 *
 * This single delegated listener turns a click on any such anchor into a
 * `related_category_click` dataLayer event, so internal-link click-through data
 * becomes visible in GTM/GA4. No consent logic here: pushing to dataLayer is a no-op
 * unless a GTM/GA4 setup consumes it, and any consent gating belongs to that setup
 * (its tags only fire once consent is granted), not to this module.
 *
 * Click tracking only (per the audit) -- no impression/IntersectionObserver view tracking.
 */
(function () {
  'use strict';

  function dl() {
    window.dataLayer = window.dataLayer || [];
    return window.dataLayer;
  }

  document.addEventListener('click', function (e) {
    try {
      var target = e.target;
      var el = (target && target.closest) ? target.closest('[data-akvarc-category-id]') : null;
      if (!el) {
        return;
      }
      var id = parseInt(el.getAttribute('data-akvarc-category-id'), 10);
      dl().push({
        event: 'related_category_click',
        category_id: isNaN(id) ? null : id,
        source: el.getAttribute('data-akvarc-source') || '',
        variant: el.getAttribute('data-akvarc-variant') || ''
      });
    } catch (err) {}
  }, true);
})();
