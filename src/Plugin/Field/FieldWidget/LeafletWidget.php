<?php
/**
 * @file
 * Contains \Drupal\leaflet_widget\Plugin\Field\FieldWidget\LeafletWidget.
 */

namespace Drupal\leaflet_widget\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\geofield\GeoPHP\GeoPHPInterface;
use Drupal\geofield\Plugin\Field\FieldWidget\GeofieldDefaultWidget;

/**
 * Plugin implementation of the "leaflet_widget" widget.
 *
 * @FieldWidget(
 *   id = "leaflet_widget",
 *   label = @Translation("Leaflet Map"),
 *   description = @Translation("Provides a map powered by Leaflet and Leaflet.widget."),
 *   field_types = {
 *     "geofield",
 *   },
 * )
 */
class LeafletWidget extends GeofieldDefaultWidget {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    $base_layers = self::getLeafletMaps();
    return parent::defaultSettings() + array(
      'map' => array(
        'leaflet_map' => array_shift($base_layers),
        'height' => 300,
        'center' => array(
          'lat' => 0.0,
          'lng' => 0.0,
        ),
        'auto_center' => TRUE,
        'zoom' => 10,
      ),
      'input' => array(
        'show' => TRUE,
        'readonly' => FALSE,
      ),
      'upload' => array(
        'show' => FALSE,
      )
    );
  }

  protected static function getLeafletMaps() {
    $options = array();
    foreach (leaflet_map_get_info() as $key => $map) {
      $options[$key] = $map['label'];
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    parent::settingsForm($form, $form_state);

    $map_settings = $this->getSetting('map');
    $form['map'] = array(
      '#type' => 'fieldset',
      '#title' => t('Map Settings'),
    );
    $form['map']['leaflet_map'] = array(
      '#title' => $this->t('Leaflet Map'),
      '#type' => 'select',
      '#options' => array('' => '-- Empty --') + $this->getLeafletMaps(),
      '#default_value' => $map_settings['leaflet_map'],
      '#required' => TRUE,
    );
    $form['map']['height'] = array(
      '#title' => $this->t('Height'),
      '#type' => 'textfield',
      '#required' => TRUE,
      '#default_value' => $map_settings['height'],
    );
    $form['map']['center'] = array(
      '#type' => 'fieldset',
      '#collapsed' => TRUE,
      '#collapsible' => TRUE,
      '#title' => 'Default map center',
    );
    $form['map']['center']['lat'] = array(
      '#type' => 'textfield',
      '#title' => t('Latitude'),
      '#default_value' => $map_settings['center']['lat'],
      '#required' => TRUE,
    );
    $form['map']['center']['lng'] = array(
      '#type' => 'textfield',
      '#title' => t('Longtitude'),
      '#default_value' => $map_settings['center']['lng'],
      '#required' => TRUE,
    );
    $form['map']['auto_center'] = array(
      '#type' => 'checkbox',
      '#title' => t('Automatically center map on existing features'),
      '#description' => t("This option overrides the widget's default center."),
      '#default_value' => $map_settings['auto_center'],
    );
    $form['map']['zoom'] = array(
      '#type' => 'textfield',
      '#title' => t('Default zoom level'),
      '#default_value' => $map_settings['zoom'],
      '#required' => TRUE,
    );

    $input_settings = $this->getSetting('input');
    $form['input'] = array(
      '#type' => 'fieldset',
      '#title' => t('Geofield Settings'),
    );
    $form['input']['show'] = array(
      '#type' => 'checkbox',
      '#title' => t('Show geofield input element'),
      '#default_value' => $input_settings['show'],
    );
    $form['input']['readonly'] = array(
      '#type' => 'checkbox',
      '#title' => t('Make geofield input element read-only'),
      '#default_value' => $input_settings['readonly'],
      '#states' => array(
        'invisible' => array(
          ':input[name="fields[field_geofield][settings_edit_form][settings][input][show]"]' => array('checked' => FALSE),
        ),
      )
    );

    $upload_settings = $this->getSetting('upload');
    $form['upload'] = array(
      '#type' => 'fieldset',
      '#title' => t('Upload Settings'),
    );
    $form['upload']['show'] = array(
      '#type' => 'checkbox',
      '#title' => t('Show file upload input element'),
      '#default_value' => $upload_settings['show'],
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    return parent::settingsSummary();
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    // Attach class to wkt input element, so we can find it in js/widget.js.
    $wkt_element_name = 'leaflet-widget-input';
    $element['value']['#attributes']['class'][] = $wkt_element_name;

    // Determine map settings and add map element.
    $map_settings = $this->getSetting('map');
    $input_settings = $this->getSetting('input');
    $upload_settings = $this->getSetting('input');
    $map = leaflet_map_get_info($map_settings['leaflet_map']);
    $map['settings']['center'] = $map_settings['center'];
    $map['settings']['zoom'] = $map_settings['zoom'];
    $element['map'] = leaflet_render_map($map, array(), $map_settings['height'] . 'px');
    $element['map']['#weight'] = -1;

    // Build JS settings for leaflet widget.
    $js_settings = array();
    $js_settings['map_id'] = $element['map']['#map_id'];
    $js_settings['wktElement'] = '.' . $wkt_element_name;
    $cardinality = $items->getFieldDefinition()
      ->getFieldStorageDefinition()
      ->getCardinality();
    $js_settings['multiple'] = $cardinality == 1 ? FALSE : TRUE;
    $js_settings['cardinality'] = $cardinality > 0 ? $cardinality : 0;
    $js_settings['autoCenter'] = $map_settings['auto_center'];
    $js_settings['inputHidden'] = empty($input_settings['show']);
    $js_settings['inputReadonly'] = !empty($input_settings['readonly']);

    // Include javascript.
    $element['map']['#attached']['library'][] = 'leaflet_widget/widget';
    // Settings and geo-data are passed to the widget keyed by field id.
    $element['map']['#attached']['drupalSettings']['leaflet_widget'] = array($element['map']['#map_id'] => $js_settings);

    if ($upload_settings['show']) {
      $element['upload']['#tree'] = TRUE;
      $element['upload']['file'] = array(
        '#type' => 'managed_file',
        '#title' => $this->t('geoJSON file upload'),
        '#upload_validators' => array(
          'file_validate_extensions' => array(
            'geojson', 'json',
          ),
        ),
      );
      $element['upload']['submit'] = array(
        '#type' => 'submit',
        '#value' => $this->t('Replace Geodata with uploaded file.'),
        '#submit' => array(array(get_class($this), 'paragraphsItemSubmit')),
      );
    }

    return $element;
  }

  public static function paragraphsItemSubmit(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();

    // Get the lump of form.
    $element = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -3));

    $field_name = $element['#field_name'];

    // Extract the values from $form_state->getValues().
    $key_exists = NULL;
    $values = NestedArray::getValue($form_state->getValues(), $element['#parents'], $key_exists);

    if (!empty($values[0]['upload']['file'][0])) {
      if ($file = file_load($values[0]['upload']['file'][0])) {
        // Load the file, parse the text and insert.
        try {
          /** @var GeoPHPInterface $geophp */
          $geophp = \Drupal::service('geofield.geophp')->load(file_get_contents($file->getFileUri()), 'json');
          // Slight tweak, if we have a geometrycollection of only one item, then unwrap.
          if ($geophp->geometryType() == 'GeometryCollection' && $geophp->numGeometries() == 1) {
            $geophp = $geophp->getComponents()[0];
          }

          $wkt = $geophp->out('wkt');
          $user_input = $form_state->getUserInput();
          $user_input['field_geodata'][0]['value'] = $wkt;
          $form_state->setUserInput($user_input);
          drupal_set_message(t('Replaced Geodata with uploaded geoJSON. Save this entity to make this change permanent'));

        }
        catch (\Exception $e) {}
      }
    }

    $form_state->setRebuild();
  }

}
