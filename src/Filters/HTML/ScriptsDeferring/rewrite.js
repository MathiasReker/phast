var Promise = phast.ES6Promise;

var go = phast.once(loadScripts);

phast.on(document, 'DOMContentLoaded').then(function () {
    if (phast.stylesLoading) {
        phast.onStylesLoaded = go;
        setTimeout(go, 4000);
    } else {
        setTimeout(go);
    }
});


var triggerLoad = false;
window.addEventListener('load', function () {
    triggerLoad = true;
});

function loadScripts() {
    var scriptsFactory = new phast.ScriptsLoader.Scripts.Factory(document, fetchScript);
    var scripts = phast.ScriptsLoader.getScriptsInExecutionOrder(document, scriptsFactory);
    if (scripts.length === 0) {
        return;
    }

    try {
        Object.defineProperty(document, 'readyState', {
            configurable: true,
            get: function() {
                return 'loading';
            }
        });
    } catch (e) {
        window.console && console.error("Phast: Unable to override document.readyState on this browser: ", e);
    }

    phast
        .ScriptsLoader
        .executeScripts(scripts)
        .then(restoreReadyState);
}

function restoreReadyState() {
    delete document['readyState'];

    triggerEvent(document, 'readystatechange');
    triggerEvent(document, 'DOMContentLoaded');

    if (triggerLoad) {
        triggerEvent(window, 'load');
    }
}

function triggerEvent(on, name) {
    var e = document.createEvent('Event');
    e.initEvent(name, true, true);
    on.dispatchEvent(e);
}


function fetchScript(element) {
    return phast.ResourceLoader.instance.get(
        phast.ResourceLoader.RequestParams.fromString(element.getAttribute('data-phast-params'))
    );
}
