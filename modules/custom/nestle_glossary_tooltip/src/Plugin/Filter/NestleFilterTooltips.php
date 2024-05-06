<?php

namespace Drupal\nestle_glossary_tooltip\Plugin\Filter;

use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Render\Renderer;

/**
 * Provides a filter to display tooltips in text.
 *
 * @Filter(
 *   id = "nestle_filter_tooltips",
 *   title = @Translation("Display tooltips in text"),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
 *   weight = -10,
 *   settings = {
 *     "filter_tooltips_vocabulary" = NULL,
 *     "filter_tooltips_occurrence_limit" = -1,
 *     "filter_tooltips_automatically" = 0,
 *     "filter_tooltips_exclude_tags" = NULL,
 *     "filter_tooltips_trigger_event" = NULL,
 *   }
 * )
 */
class NestleFilterTooltips extends FilterBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('renderer')
    );
  }

  /**
   * Constructor function for the filter.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager to pull out the entity data.
   * @param \Drupal\Core\Render\Renderer $renderer
   *   The renderer service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Renderer $renderer
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    // Load all vocabularies.
    $vocabularies = $this->entityTypeManager->getStorage('taxonomy_vocabulary')
      ->loadMultiple();
    if (empty($vocabularies)) {
      // Show link for adding a vocabulary.
      $form['no_vocabularies'] = [
        '#type' => 'item',
        '#markup' => $this->t('Create a @link with words and explanations.', [
          '@link' => Link::createFromRoute($this->t('vocabulary'), 'entity.taxonomy_vocabulary.add_form')
            ->toString(),
        ]),
      ];
    }
    else {
      // Generate an array of options for the dropdown.
      $vocabulary_list = [];
      foreach ($vocabularies as $vocabulary) {
        $vocabulary_list[$vocabulary->id()] = $vocabulary->get('name');
      }
      // Add the vocabulary dropdown.
      $form['filter_tooltips_vocabulary'] = [
        '#type' => 'select',
        '#title' => $this->t('Source vocabulary'),
        '#options' => $vocabulary_list,
        '#default_value' => $this->settings['filter_tooltips_vocabulary'],
        '#description' => $this->t('Select the vocabulary which you want to use as a source.'),
      ];

      $form['filter_tooltips_automatically'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Add tooltips automatically'),
        '#default_value' => $this->settings['filter_tooltips_automatically'],
        '#description' => $this->t('Whether the words should be replaced automatically. For manual replacement use the CKEditor plugin.'),
      ];

      $form['filter_tooltips_occurrence_limit'] = [
        '#type' => 'number',
        '#title' => $this->t('Limit occurrence'),
        '#min' => -1,
        '#default_value' => $this->settings['filter_tooltips_occurrence_limit'],
        '#description' => $this->t('Limit the occurrence of texts to replace. To replace only the first occurrence, enter 1. For all enter -1.'),
        '#states' => [
          'visible' => [
            ':input[name="filters[filter_tooltips][settings][filter_tooltips_automatically]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['filter_tooltips_exclude_tags'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Exclude tags'),
        '#min' => -1,
        '#default_value' => $this->settings['filter_tooltips_exclude_tags'],
        '#description' => $this->t('Do not show tooltips within tags. Eg. add "h1 div" to prevent the tooltips from rendering within h1 and div tags.'),
        '#states' => [
          'visible' => [
            ':input[name="filters[filter_tooltips][settings][filter_tooltips_automatically]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['filter_tooltips_trigger_event'] = [
        '#type' => 'select',
        '#title' => $this->t('Trigger event'),
        '#options' => [
          'click' => $this->t('Click'),
          'mouseover' => $this->t('Mouseover'),
        ],
        '#default_value' => $this->settings['filter_tooltips_trigger_event'],
        '#description' => $this->t('Select the event to trigger the tooltips.'),
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $vocabulary_vid = $this->settings['filter_tooltips_vocabulary'];
    $vocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')
      ->load($vocabulary_vid);
    if (empty($vocabulary)) {
      $result = new FilterProcessResult($text);
    }

    $replaced_text = $this->replaceWords($text, $langcode);
    $result = new FilterProcessResult($replaced_text);

    $trigger_event = 'click';
    if (!empty($this->settings['filter_tooltips_trigger_event'])) {
      $trigger_event = $this->settings['filter_tooltips_trigger_event'];
    }

    $result->setAttachments([
      'library' => [
        'nestle_glossary_tooltip/tooltips',
      ],
      'drupalSettings' => [
        'filter_tooltips' => [
          'trigger_event' => $trigger_event,
        ],
      ],
    ]);

    $result->addCacheTags($vocabulary->getCacheTags());
    $result->addCacheContexts($vocabulary->getCacheContexts());

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    return $this->t('Replace tooltips in text.');
  }

  /**
   * Replace the words in the text.
   *
   * @param string $text
   *   A string containing the text from the WYSYWIG editor.
   * @param string $langcode
   *   A string containing the user's language code.
   *
   * @return string
   *   The text with the replaced words.
   */
  private function replaceWords($text, $langcode) {
    // Check if replacements are automatically.
    if ($this->settings['filter_tooltips_automatically']) {
      // This replaces the manual syntax too.
      return $this->autoReplace($text, $langcode);
    }
    return $text;
  }

  /**
   * Automatically replace the words in the text.
   *
   * @param string $text
   *   A string containing the text from the WYSYWIG editor.
   * @param string $langcode
   *   A string containing the user's language code.
   *
   * @return string
   *   The text with the replaced words.
   */
  private function autoReplace($text, $langcode) {
    if (empty($text)) {
      return '';
    }

    // Load all terms using the user's language code.
    $terms = $this->getTaxonomyTerms($langcode);
    if (empty($terms)) {
      return $text;
    }

    // Get the occurrence limit.
    $occurrence_limit = $this->settings['filter_tooltips_occurrence_limit'];

    // Get all the keys of the terms.
    $term_keys = array_keys($terms);

    // Build a regex to exclude tags.
    $excluded_tags = $this->settings['filter_tooltips_exclude_tags'];
    $excluded_tags_regex = '';
    if (!empty($excluded_tags)) {
      $excluded_tags_arr = explode(' ', $excluded_tags);
      foreach ($excluded_tags_arr as $excluded_tag) {
        $excluded_tags_regex .= '(?!(.(?!<' . $excluded_tag . '))*<\/' . $excluded_tag . '>)';
      }
    }

    // Find all terms in content. A tags are always excluded because it renders
    // a link in a link.
    $regex = '/(' . implode('|', $term_keys) . ')(?!(.(?!<a))*<\/a>)' . $excluded_tags_regex . '/i';

    $list = [];

    // Replace the words using an anonymous function.
    return preg_replace_callback($regex, function($matches) use ($terms, $occurrence_limit, &$list) {
      // Clean the name.
      $name = $this->cleanName($matches[0]);

      // Get the current occurrence of the term.
      $occurence = 0;
      if (!empty($list[$name])) {
        $occurence = $list[$name];
      }

      // When the occurrence exceeds the limit return the unconverted term.
      if ($occurrence_limit >= -1 && $occurrence_limit == $occurence) {
        return $matches[0];
      }

      // Increment the occurrence.
      $list[$name] = $occurence + 1;

      // Return the rendered html.
      return $this->render($matches[0], $terms[$name]);
    }, $text);
  }

  /**
   * Render the tooltip HTML.
   *
   * @param string $title
   *   A string containing the title of the tooltip.
   * @param string $description
   *   A string containing the description of the tooltip.
   *
   * @return string
   *   The rendered tooltip HTML.
   */
  private function render($title, $description) {
    if (!empty($title) && empty($description)) {
      return $title;
    }
    elseif (empty($title) || empty($description)) {
      return '[broken-tooltip]';
    }

    // Replace the term by a tooltip.
    $renderable = [
      '#theme' => 'nestle_glossary_tooltip',
      '#title' => $title,
      '#description' => $description,
    ];

    return $this->renderer->render($renderable);
  }

  /**
   * Get the terms from the configured taxonomy vocabulary.
   *
   * @param string $langcode
   *   A string containing the user's language code.
   *
   * @return array
   *   An array containing the words to replace and their explanation.
   */
  private function getTaxonomyTerms($langcode) {
    $terms = [];
    // Get the vocabulary setting.
    $vocabulary_vid = $this->settings['filter_tooltips_vocabulary'];
    if (!empty($vocabulary_vid)) {
      // Load all terms by vocabulary and user's language code.
      $taxonomy_terms = $this->entityTypeManager->getStorage('taxonomy_term')
        ->loadByProperties([
          'vid' => $vocabulary_vid,
          'langcode' => $langcode,
        ]);

      if (!empty($taxonomy_terms)) {
        foreach ($taxonomy_terms as $term) {
          // Skip when a description is missing.
          if (empty($term->getDescription())) {
            continue;
          }

          // Clean the name.
          $name = $this->cleanName($term->getName());

          // Add the name and description to an array.
          if (strlen($term->getDescription()) <= 100) {
            $terms[$name] = $term->getDescription();
          }
          else {
            //$terms[$name] = mb_strimwidth($term->getDescription(), 0, 50, "...<span class='show-more'> READ MORE </span>");
            $chunks = str_split($term->getDescription(), 100);
            $terms[$name] = $chunks[0] . ' ... <a class="read-more" href="/taxonomy/term/' . $term->id() . '">' . t('Read more') . '</a>';
          }
        }
      }
    }
    return $terms;
  }

  /**
   * Function to clean the name.
   *
   * @param string $name
   *   A string containing a raw name.
   *
   * @return string
   *   A string containing a clean name.
   */
  private function cleanName($name) {
    // Convert title to lowercase.
    $name = mb_strtolower($name);

    // Escape characters.
    $name = preg_quote($name, '/');

    return $name;
  }

}
