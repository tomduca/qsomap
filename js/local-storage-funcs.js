/////////////////////////////
// LOCAL STORAGE FUNCTIONS //
/////////////////////////////

// Load from local storage and set GUI up appropriately
function loadLocalStorage() {
    // Populate band colour scheme options before setting values
    getAvailableBandColorSchemes().forEach(sc => $("#bandColorScheme").append($('<option>', {value: sc, text: sc})));

    // Load all named form inputs from localStorage
    $('#main-form [name]').each(function() {
        const val = localStorage.getItem(this.name);
        if (val !== null) {
            if (this.type === 'checkbox') {
                $(this).prop('checked', JSON.parse(val));
            } else if (this.type === 'radio') {
                $(this).prop('checked', this.value === JSON.parse(val));
            } else {
                $(this).val(JSON.parse(val));
            }
        }
    });

    // Apply side-effects that depend on the loaded values
    setBandColorSchemeQSOMap($("#bandColorScheme").val());

    // Load lookup data. This had to be converted to an object for storage, now we need it back as a map.
    const lookupDataStr = localStorage.getItem('lookupData');
    if (lookupDataStr !== null) {
        lookupData = new Map(Object.entries(JSON.parse(lookupDataStr)));
    }

    // Re-apply theme and display settings now that the various controls have been set to their stored values
    setColorScheme();
    updateDisplay();
}
