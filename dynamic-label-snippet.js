// usage: data-dynamic-label="Some words here [238] another here [237] and here too"
if (jQuery(`[data-dynamic-label]`).length > 0) jQuery(`[data-dynamic-label]`).each(function () {
    var target = jQuery(this)
    var label_template = jQuery(this).attr(`data-dynamic-label`)
    var fields = label_template.split(`]`)
    fields = fields.map(field => {
        var get_field_id = field.split(`[`)
        return get_field_id[1] ? parseInt(get_field_id[1]) : null
    })
    fields = fields.filter(function (id) { return null !== id })
    var selectors = fields.map(field => {
        return `[name="item_meta[${field}]"]`
    })
    var selector_join = selectors.join(`,`)
    jQuery(`${selector_join}`).change(function () {
        var label = label_template
        for (var field of fields) label = label.replace(`[${field}]`, jQuery(`[name="item_meta[${field}]"]`).val())
        target.html(label)
    })
})