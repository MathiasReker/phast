var Promise = phast.ES6Promise;

phast.ScriptsLoader = {};

phast.ScriptsLoader.Utilities = function (document) {

    var insertBefore = Element.prototype.insertBefore;

    function scriptFromPhastScript(original) {
        var newScript = document.createElement('script');
        Array.prototype.forEach.call(original.attributes, function (attr) {
            var attrName;
            var phastAttr = attr.nodeName.match(/^data-phast-original-(.*)/i);
            if (phastAttr) {
                attrName = phastAttr[1];
            } else {
                attrName = attr.nodeName;
            }
            newScript.setAttribute(attrName, attr.nodeValue);
        });
        return newScript;
    }

    function copySrc(source, target) {
        target.setAttribute('src', source.getAttribute('src'));
    }

    function setOriginalSrc(original, target) {
        target.setAttribute('src', original.getAttribute('data-phast-original-src'));
    }

    function setOriginalType(original, target) {
        target.setAttribute('type', original.getAttribute('data-phast-original-type'));
    }

    function executeString(string) {
        return new Promise(function (resolve, reject) {
            try {
                // See: http://perfectionkills.com/global-eval-what-are-the-options/
                (1,eval)(string);
            } catch (e) {
                console.error("[Phast] Error in inline script:", e);
                console.log("First 100 bytes of script body:", string.substr(0, 100));
                reject(e);
            }
            resolve();
        })
    }

    function copyElement(source) {
        var copy = document.createElement(source.nodeName);
        Array.prototype.forEach.call(source.attributes, function (attr) {
            copy.setAttribute(attr.nodeName, attr.nodeValue);
        });
        return copy;
    }

    function restoreOriginals(element) {
        Array.prototype
            .map.call(element.attributes, function (attr) {
                return attr.nodeName;
            })
            .forEach(function (attrName) {
                var matches = attrName.match(/^data-phast-original-(.*)/i);
                if (matches) {
                    element.setAttribute(matches[1], element.getAttribute(attrName));
                    element.removeAttribute(attrName);
                }
            });
    }

    function replaceElement(target, replacement) {
        return new Promise(function (resolve, reject) {
            replacement.addEventListener('load', resolve);
            replacement.addEventListener('error', reject);
            insertBefore.call(target.parentNode, replacement, target);
            target.parentNode.removeChild(target);
        });
    }

    function writeProtectAndExecuteString(sourceElement, scriptString) {
        return writeProtectAndCallback(sourceElement, function () {
            return executeString(scriptString);
        });
    }

    function writeProtectAndReplaceElement(target, replacement) {
        return writeProtectAndCallback(replacement, function () {
            return replaceElement(target, replacement);
        });
    }

    function writeProtectAndCallback(sourceElement, callback) {
        var writeBuffer = '';
        document.write = function (string) {
            writeBuffer += string;
        };
        document.writeln = function (string) {
            writeBuffer += string + '\n';
        };
        return callback()
            .then(function () {
                sourceElement.insertAdjacentHTML('afterend', writeBuffer);
            })
            .finally(function () {
                delete document.write;
                delete document.writeln;
            });
    }

    this.scriptFromPhastScript = scriptFromPhastScript;
    this.copySrc = copySrc;
    this.setOriginalSrc = setOriginalSrc;
    this.setOriginalType = setOriginalType;
    this.executeString = executeString;
    this.copyElement = copyElement;
    this.restoreOriginals = restoreOriginals;
    this.replaceElement = replaceElement;
    this.writeProtectAndExecuteString = writeProtectAndExecuteString;
    this.writeProtectAndReplaceElement = writeProtectAndReplaceElement;
};

phast.ScriptsLoader.Scripts = {};

phast.ScriptsLoader.Scripts.InlineScript = function (utils, element) {

    this.init = function () {};

    this.execute = function () {
        utils.restoreOriginals(element);
        return utils.executeString(element.textContent.replace(/\s*<!--\s*.*?\n/i, ''));
    };
};

phast.ScriptsLoader.Scripts.AsyncBrowserScript = function (utils, element) {

    this.init = function  () {
        var newElement = utils.copyElement(element);
        utils.restoreOriginals(newElement);
        utils.replaceElement(element, newElement);
    };

    this.execute = function () {
        return Promise.resolve();
    };
};

phast.ScriptsLoader.Scripts.SyncBrowserScript = function (utils, element) {

    this.init = function () {};

    this.execute = function () {
        var newElement = utils.copyElement(element);
        utils.restoreOriginals(newElement);
        return utils.writeProtectAndReplaceElement(element, newElement);
    };
};

phast.ScriptsLoader.Scripts.AsyncAJAXScript = function (utils, element, fetch, fallback) {

    this.init = function () {
        fetch(element.getAttribute('src'))
            .then(function (execString) {
                utils.restoreOriginals(element);
                utils.executeString(execString);
            })
            .catch(function () {
                fallback.init();
            });
    };

    this.execute = function () {
        return Promise.resolve();
    };
};

phast.ScriptsLoader.Scripts.SyncAJAXScript = function (utils, element, fetch, fallback) {

    var promise;
    this.init = function () {
        promise = fetch(element.getAttribute('src'));
    };

    this.execute = function () {
        return promise
            .then(function (execString) {
                utils.restoreOriginals(element);
                utils.writeProtectAndExecuteString(element, execString);
            })
            .catch(function () {
                fallback.init();
                return fallback.execute();
            });
    };

};
