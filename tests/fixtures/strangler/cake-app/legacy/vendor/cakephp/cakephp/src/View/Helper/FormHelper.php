<?php
declare(strict_types=1);

// Test fixture: the $_defaultConfig of CakePHP 5.2 FormHelper (MIT License, Cake Software Foundation), which Booster reads at move time.

namespace Cake\View\Helper;

class FormHelper
{
    protected array $_defaultConfig = [
        'idPrefix' => null,
        // Deprecated option, use templates.errorClass intead.
        'errorClass' => null,
        'defaultPostLinkBlock' => null,
        'typeMap' => [
            'string' => 'text',
            'text' => 'textarea',
            'uuid' => 'string',
            'datetime' => 'datetime',
            'datetimefractional' => 'datetime',
            'timestamp' => 'datetime',
            'timestampfractional' => 'datetime',
            'timestamptimezone' => 'datetime',
            'date' => 'date',
            'time' => 'time',
            'year' => 'year',
            'boolean' => 'checkbox',
            'float' => 'number',
            'integer' => 'number',
            'tinyinteger' => 'number',
            'smallinteger' => 'number',
            'decimal' => 'number',
            'binary' => 'file',
        ],
        'templates' => [
            // Used for button elements in button().
            'button' => '<button{{attrs}}>{{text}}</button>',
            // Used for checkboxes in checkbox() and multiCheckbox().
            'checkbox' => '<input type="checkbox" name="{{name}}" value="{{value}}"{{attrs}}>',
            // Input group wrapper for checkboxes created via control().
            'checkboxFormGroup' => '{{label}}',
            // Wrapper container for checkboxes in a multicheckbox input
            'checkboxWrapper' => '<div class="checkbox">{{label}}</div>',
            // Error message wrapper elements.
            'error' => '<div class="error-message" id="{{id}}">{{content}}</div>',
            // Container for error items.
            'errorList' => '<ul>{{content}}</ul>',
            // Error item wrapper.
            'errorItem' => '<li>{{text}}</li>',
            // File input used by file().
            'file' => '<input type="file" name="{{name}}"{{attrs}}>',
            // Fieldset element used by allControls().
            'fieldset' => '<fieldset{{attrs}}>{{content}}</fieldset>',
            // Open tag used by create().
            'formStart' => '<form{{attrs}}>',
            // Close tag used by end().
            'formEnd' => '</form>',
            // General grouping container for control(). Defines input/label ordering.
            'formGroup' => '{{label}}{{input}}',
            // Wrapper content used to hide other content.
            'hiddenBlock' => '<div{{attrs}}>{{content}}</div>',
            // Generic input element.
            'input' => '<input type="{{type}}" name="{{name}}"{{attrs}}>',
            // Submit input element.
            'inputSubmit' => '<input type="{{type}}"{{attrs}}>',
            // Container element used by control().
            'inputContainer' => '<div class="{{containerClass}} {{type}}{{required}}">{{content}}</div>',
            // Container element used by control() when a field has an error.
            // phpcs:ignore
            'inputContainerError' => '<div class="{{containerClass}} {{type}}{{required}} error">{{content}}{{error}}</div>',
            // Label element when inputs are not nested inside the label.
            'label' => '<label{{attrs}}>{{text}}</label>',
            // Label element used for radio and multi-checkbox inputs.
            'nestingLabel' => '{{hidden}}<label{{attrs}}>{{input}}{{text}}</label>',
            // Legends created by allControls()
            'legend' => '<legend>{{text}}</legend>',
            // Multi-Checkbox input set title element.
            'multicheckboxTitle' => '<legend>{{text}}</legend>',
            // Multi-Checkbox wrapping container.
            'multicheckboxWrapper' => '<fieldset{{attrs}}>{{content}}</fieldset>',
            // Option element used in select pickers.
            'option' => '<option value="{{value}}"{{attrs}}>{{text}}</option>',
            // Option group element used in select pickers.
            'optgroup' => '<optgroup label="{{label}}"{{attrs}}>{{content}}</optgroup>',
            // Select element,
            'select' => '<select name="{{name}}"{{attrs}}>{{content}}</select>',
            // Multi-select element,
            'selectMultiple' => '<select name="{{name}}[]" multiple="multiple"{{attrs}}>{{content}}</select>',
            // Radio input element,
            'radio' => '<input type="radio" name="{{name}}" value="{{value}}"{{attrs}}>',
            // Wrapping container for radio input/label,
            'radioWrapper' => '{{label}}',
            // Textarea input element,
            'textarea' => '<textarea name="{{name}}"{{attrs}}>{{value}}</textarea>',
            // Container for submit buttons.
            'submitContainer' => '<div class="submit">{{content}}</div>',
            // Confirm javascript template for postLink()
            'confirmJs' => '{{confirm}}',
            // Templates for postLink() JS for <script> tag. (used for CSP)
            'postLinkJs'
                => 'document.getElementById("{{linkId}}").addEventListener("click", function(event) { {{content}} });',
            // selected class
            'selectedClass' => 'selected',
            // required class
            'requiredClass' => 'required',
            // CSS class added to the input when the field has validation errors
            'errorClass' => 'form-error',
            // Class to use instead of "display:none" style attribute for hidden elements
            'hiddenClass' => '',
            // CSS class added to the input containers
            'containerClass' => 'input',
        ],
        // set HTML5 validation message to custom required/empty messages
        'autoSetCustomValidity' => true,
    ];
}
