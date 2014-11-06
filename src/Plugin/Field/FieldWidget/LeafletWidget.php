<?php
/**
 * @file
 * Contains \Drupal\leaflet_widget\Plugin\Field\FieldWidget\LeafletWidget.
 */

namespace Drupal\leaflet_widget\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
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

    $settings = $this->getSetting('map');

    $form['map'] = array(
      '#type' => 'fieldset',
      '#title' => t('Default map settings'),
    );
    $form['map']['leaflet_map'] = array(
      '#title' => $this->t('Leaflet Map'),
      '#type' => 'select',
      '#options' => array('' => '-- Empty --') + $this->getLeafletMaps(),
      '#default_value' => $settings['leaflet_map'],
      '#required' => TRUE,
    );
    $form['map']['height'] = array(
      '#title' => $this->t('Height'),
      '#type' => 'textfield',
      '#required' => TRUE,
      '#default_value' => $settings['height'],
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
      '#default_value' => $settings['center']['lat'],
      '#required' => TRUE,
    );
    $form['map']['center']['lng'] = array(
      '#type' => 'textfield',
      '#title' => t('Longtitude'),
      '#default_value' => $settings['center']['lng'],
      '#required' => TRUE,
    );
    $form['map']['auto_center'] = array(
      '#type' => 'checkbox',
      '#title' => t('Automatically center map on existing features'),
      '#description' => t("This option overrides the widget's default center."),
      '#default_value' => $settings['auto_center'],
    );
    $form['map']['zoom'] = array(
      '#type' => 'textfield',
      '#title' => t('Default zoom level'),
      '#default_value' => $settings['zoom'],
      '#required' => TRUE,
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

    // Include javascript.
    $element['map']['#attached']['library'][] = 'leaflet_widget/widget';
    // Settings and geo-data are passed to the widget keyed by field id.
    $element['map']['#attached']['js'][] = array(
      'type' => 'setting',
      'data' => array('leaflet_widget' => array($element['map']['#map_id'] => $js_settings)),
    );

    return $element;
  }

}
