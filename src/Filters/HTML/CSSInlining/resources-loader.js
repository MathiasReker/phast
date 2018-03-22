phast.ResourceLoader = {};

phast.ResourceLoader.Request = function () {

    var onsuccess,
        onerror,
        onend,
        successText,
        success,
        resolved = false,
        called = false;

    Object.defineProperty(this, 'onsuccess', {

        get: function () {
            return onsuccess;
        },

        set: function (func) {
            onsuccess = func;
            if (resolved && success) {
                this.success(successText);
            }
        }
    });

    Object.defineProperty(this, 'onerror', {

        get: function () {
            return onerror;
        },

        set: function (func) {
            onerror = func;
            if (resolved && !success) {
                this.error();
            }
        }
    });

    Object.defineProperty(this, 'onend', {

        get: function () {
            return onend;
        },

        set: function (func) {
            onend = func;
            if (resolved) {
                func();
            }
        }
    });

    this.success = function (responseText) {
        resolved = true;
        successText = responseText;
        success = true;
        if (!called && onsuccess) {
            onsuccess(responseText);
            called = true;
        }
        end();
    };

    this.error = function () {
        resolved = true;
        success = false;
        if (!called && onerror) {
            onerror();
            called = true;
        }
        end();
    };

    function end() {
        if (!called && onend) {
            onend();
        }
    }
};

phast.ResourceLoader.RequestParams = function (faulty) {

    this.isFaulty = function () {
        return faulty;
    };
};

phast.ResourceLoader.RequestParams.fromString = function(string) {
    try {
        var parsed = JSON.parse(string);
        return phast.ResourceLoader.RequestParams.fromObject(parsed);
    } catch (e) {
        return new phast.ResourceLoader.RequestParams(true);
    }
};

phast.ResourceLoader.RequestParams.fromObject = function (parsed) {
    var params = new phast.ResourceLoader.RequestParams(false);
    for (var x in parsed) {
        params[x] = parsed[x];
    }
    return params;
};

phast.ResourceLoader.BundlerServiceClient = function (serviceUrl) {

    var timeoutHandler;
    var accumulatingPack = [];

    this.get = function (params) {
        var request = new phast.ResourceLoader.Request();
        if (params.isFaulty()) {
            request.error();
            return request;
        }
        accumulatingPack.push(new PackItem(request, params));
        clearTimeout(timeoutHandler);
        timeoutHandler = setTimeout(this.flush);
        return request;
    };

    this.flush = function () {
        var pack = accumulatingPack;
        accumulatingPack = [];
        clearTimeout(timeoutHandler);
        makeRequest(pack);
    };

    function packToQuery(pack) {
        var glue = serviceUrl.indexOf('?') > -1 ? '&' : '?';
        var parts = [];
        pack.forEach(function (item, idx) {
            for (var key in item.params) {
                if (key === 'isFaulty') {
                    continue;
                }
                parts.push(encodeURIComponent(key) + '_' + idx + '=' + encodeURIComponent(item.params[key]));
            }
        });
        return serviceUrl + glue + parts.join('&');
    }

    function makeRequest(pack) {
        var query = packToQuery(pack);
        var errorHandler = function () {
            handleError(pack);
        };
        var successHandler = function () {
            if (xhr.status >= 200 && xhr.status < 300) {
                handleResponse(xhr.responseText, pack);
            } else {
                handleError(pack);
            }
        };
        var xhr = new XMLHttpRequest();
        xhr.open('GET', query);
        xhr.addEventListener('error', errorHandler);
        xhr.addEventListener('abort', errorHandler);
        xhr.addEventListener('load', successHandler);
        xhr.send();
    }

    function handleError(pack) {
        pack.forEach(function (item) {
            try {
                item.request.error();
            } catch (e) {}
        });
    }

    function handleResponse(responseText, pack) {
        try {
            var responses = JSON.parse(responseText);
        } catch (e) {
            handleError(pack);
            return;
        }
        responses.forEach(function (response, idx) {
            try {
                if (response.status === 200) {
                    pack[idx].request.success(response.content);
                } else {
                    pack[idx].request.error();
                }
            } catch (e) {};
        });
    }

    function PackItem(request, params) {
        this.request = request;
        this.params = params;
    }

};

phast.ResourceLoader.IndexedDBResourceCache = function (client) {

    var storeName = 'resources';

    this.get = function (params) {
        var request = new phast.ResourceLoader.Request();
        var cacheRequest = getFromCache(params);
        cacheRequest.onsuccess = request.success;
        cacheRequest.onerror = function () {
            getFromClient(params, request);
        };
        return request;
    };

    function getFromCache(params) {
        var request = new phast.ResourceLoader.Request();
        var dbOpenRequest = openDB();
        dbOpenRequest.onerror = function () {
            request.error();
        };
        dbOpenRequest.onsuccess = function () {
            var db = dbOpenRequest.result;
            var storeRequest = db
                .transaction(storeName)
                .objectStore(storeName)
                .get(params.token);
            storeRequest.onerror = function () {
                request.error();
            };
            storeRequest.onsuccess = function () {
                if (storeRequest.result) {
                    request.success(storeRequest.result.content);
                } else {
                    request.error();
                }
                db.close();
            };
        };
        return request;
    }

    function getFromClient(params, request) {
        var clientRequest = client.get(params);
        clientRequest.onerror = request.error;
        clientRequest.onsuccess = function (responseText) {
            storeInCacheAndFinishRequest(params, responseText);
            request.success(responseText);
        };
    }

    function storeInCacheAndFinishRequest(params, responseText) {
        var dbOpenRequest = openDB();
        dbOpenRequest.onsuccess = function () {
            var db = dbOpenRequest.result;
            var putRequest = db.transaction(storeName, 'readwrite')
                .objectStore(storeName)
                .put({token: params.token, content: responseText});
            putRequest.onerror = function () {
                db.close();
            };
            putRequest.onsuccess = function () {
                db.close();
            };
        };
    }

    function openDB() {
        var dbOpenRequest = indexedDB.open('phastResourcesCache', 1);
        dbOpenRequest.onupgradeneeded = function () {
            createDB(dbOpenRequest.result);
        };
        return dbOpenRequest;
    }

    function createDB(db) {
        db.createObjectStore(storeName, {keyPath: 'token'});
    }

};




