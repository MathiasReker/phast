var Promise = phast.ES6Promise.Promise;

phast.ResourceLoader = function (client, cache) {

    this.get = addToRequested;

    var requested = [];
    var executionTimeout;

    function addToRequested(params) {
        return new Promise(function (resolve, reject) {
            requested.push({resolve: resolve, reject: reject, params: params});
            clearTimeout(executionTimeout);
            executionTimeout = setTimeout(getFromCache);
        });
    }

    function getFromCache() {
        var misses = [];
        Promise.all(requested.map(function (request) {
            return cache.get(request.params)
                .then(function (content) {
                    if (content) {
                        request.resolve(content);
                    } else {
                        return Promise.reject();
                    }
                })
                .catch(function () {
                    misses.push(request);
                });
        }))
        .then(function () {
            getFromClient(misses);
        });
        requested = [];
    }

    function getFromClient(requests) {
        requests.forEach(function (request) {
            client.get(request.params)
                .then(function (responseText) {
                    cache.set(request.params, responseText);
                    request.resolve(responseText);
                })
                .catch(request.reject);
        });
    }
};

phast.ResourceLoader.RequestParams = {};

phast.ResourceLoader.RequestParams.FaultyParams = {};

phast.ResourceLoader.RequestParams.fromString = function(string) {
    try {
        return JSON.parse(string);
    } catch (e) {
        return phast.ResourceLoader.RequestParams.FaultyParams;
    }
};

phast.ResourceLoader.BundlerServiceClient = function (serviceUrl, shortParamsMappings) {

    var RequestsPack = phast.ResourceLoader.BundlerServiceClient.RequestsPack;
    var PackItem = RequestsPack.PackItem;

    var timeoutHandler;
    var accumulatingPack = new RequestsPack(shortParamsMappings);

    this.get = function (params) {
        return new Promise(function (resolve, reject) {
            if (params === phast.ResourceLoader.RequestParams.FaultyParams) {
                reject(new Error("Parameters did not parse as JSON"));
            } else {
                accumulatingPack.add(new PackItem({success: resolve, error: reject}, params));
                clearTimeout(timeoutHandler);
                timeoutHandler = setTimeout(flush);
                if (accumulatingPack.toQuery().length > 4500) {
                    console.log("[Phast] Resource loader: Pack got too big; flushing early...");
                    flush();
                }
            }
        });
    };

    function flush() {
        var pack = accumulatingPack;
        accumulatingPack = new RequestsPack(shortParamsMappings);
        clearTimeout(timeoutHandler);
        makeRequest(pack);
    }

    function makeRequest(pack) {
        var glue = serviceUrl.indexOf('?') > -1 ? '&' : '?';
        var query = serviceUrl + glue + pack.toQuery();
        var errorHandler = function () {
            pack.handleError();
        };
        var successHandler = function () {
            if (xhr.status >= 200 && xhr.status < 300) {
                pack.handleResponse(xhr.responseText);
            } else {
                pack.handleError();
            }
        };
        var xhr = new XMLHttpRequest();
        xhr.open('GET', query);
        xhr.addEventListener('error', errorHandler);
        xhr.addEventListener('abort', errorHandler);
        xhr.addEventListener('load', successHandler);
        xhr.send();
    }
};

phast.ResourceLoader.BundlerServiceClient.RequestsPack = function (shortParamsMappings) {

    var items = {};

    function addToPack(packItem) {
        if (!items[packItem.params.token]) {
            items[packItem.params.token] = {
                params: packItem.params,
                requests: [packItem.request]
            };
        } else {
            items[packItem.params.token]
                .requests
                .push(packItem.request);
        }
    }

    function packToQuery() {
        var parts = [],
            cacheMarkers = [],
            lastSrc = '';
        getSortedTokens().forEach(function (token) {
            var queryKey,  queryValue;
            for (var key in items[token].params) {
                if (key === 'cacheMarker') {
                    cacheMarkers.push(items[token].params.cacheMarker);
                    continue;
                }
                queryKey = shortParamsMappings[key] ? shortParamsMappings[key] : key;
                if (key === 'strip-imports') {
                    queryValue = encodeURIComponent(queryKey);
                } else if (key === 'src') {
                    queryValue = encodeURIComponent(queryKey)
                        + '='
                        + encodeURIComponent(
                            compressSrc(items[token].params.src, lastSrc)
                        );
                    lastSrc = items[token].params.src;
                } else {
                    queryValue = encodeURIComponent(queryKey) + '=' + encodeURIComponent(items[token].params[key]);
                }
                parts.push(queryValue);
            }
        });
        if (cacheMarkers.length > 0) {
            parts.unshift('c=' + phast.hash(cacheMarkers.join('|'), 23045));
        }
        return parts.join('&');
    }

    function getSortedTokens() {
        var paramsArr = [];
        for (var token in items) {
            paramsArr.push(items[token].params);
        }
        return paramsArr
            .sort(function (a, b) {
                return a.src > b.src ? 1 : -1
            })
            .map(function (item) {
                return item.token;
            })
    }

     function compressSrc(src, lastSrc) {
         var prefixLen = 0, maxBase36Val = Math.pow(36, 2) - 1;
         while (prefixLen < lastSrc.length && src[prefixLen] === lastSrc[prefixLen]) {
             prefixLen++;
         }
         prefixLen = Math.min(prefixLen, maxBase36Val);
         return toBase36(prefixLen) + '' + src.substr(prefixLen);
     }

     function toBase36(dec) {
         var charsTable = [
             '0', '1', '2', '3', '4', '5',
             '6', '7', '8', '9', 'a', 'b',
             'c', 'd', 'e', 'f', 'g', 'h',
             'i', 'j', 'k', 'l', 'm', 'n',
             'o', 'p', 'q', 'r', 's', 't',
             'u', 'v', 'w', 'x', 'y', 'z'
         ];
         var p1 = dec % 36;
         var p2 = Math.floor((dec - p1) / 36);
         return charsTable[p2] + charsTable[p1];
     }

    function handleResponse(responseText) {
        try {
            var responses = JSON.parse(responseText);
        } catch (e) {
            handleError();
            return;
        }

        var tokens = getSortedTokens();
        responses.forEach(function (response, idx) {
            if (response.status === 200) {
                items[tokens[idx]].requests.forEach(function (request) {
                    request.success(response.content)
                });
            } else {
                items[tokens[idx]].requests.forEach(function (request) {
                    request.error(new Error("Got from bundler: " + JSON.stringify(response)));
                });
            }
        });
    }

    function handleError() {
        for (var k in items) {
            items[k].requests.forEach(function (request) {
                request.error();
            });
        }
    }

    this.add = addToPack;

    this.handleResponse = handleResponse;

    this.handleError = handleError;

    this.toQuery = packToQuery;
};

phast.ResourceLoader.BundlerServiceClient.RequestsPack.PackItem = function (request, params) {
    this.request = request;
    this.params = params;
};

phast.ResourceLoader.IndexedDBStorage = function (params) {

    var Storage = phast.ResourceLoader.IndexedDBStorage;
    var logPrefix = Storage.logPrefix;
    var r2p = Storage.requestToPromise;

    var con;

    connect();

    this.get = function (key) {
        return con.get()
            .then(function (db) {
                return r2p(getStore(db).get(key));
            })
            .catch(function (e) {
                console.error(logPrefix, 'Error reading from store:', e);
                resetConnection();
                throw e;
            });
    };

    this.store = function (item) {
        return con.get()
            .then(function (db) {
                return r2p(getStore(db, 'readwrite').put(item));
            })
            .catch(function (e) {
                console.error(logPrefix, 'Error writing to store:', e);
                resetConnection();
                throw e;
            });
    };

    this.clear = function () {
        return con.get()
            .then(function (db) {
                return r2p(getStore(db, 'readwrite').clear());
            });
    };

    this.iterateOnAll = function (callback) {
        return con.get()
            .then(function (db) {
                return iterateOnRequest(callback, getStore(db).openCursor());
            })
            .catch(function (e) {
                console.error(logPrefix, 'Error iterating on all:', e);
                resetConnection();
                throw e;
            });
    };

    function iterateOnRequest(callback, request) {
        return new Promise(function (resolve, reject) {
            request.onsuccess = function (ev) {
                var cursor = ev.target.result;
                if (cursor) {
                    callback(cursor.value);
                    cursor.continue();
                } else {
                    resolve();
                }
            };
            request.onerror = reject;
        });
    }

    function getStore(db, mode) {
        mode = mode || 'readonly';
        return db
            .transaction(params.storeName, mode)
            .objectStore(params.storeName);
    }

    function resetConnection() {
        var dropPromise = con.dropDB().then(connect);
        con = {
            get: function () {
                return Promise.reject(new Error('Resetting DB'))
            },

            dropDB: function () {
                return dropPromise;
            }
        };

    }

    function connect() {
        con = new phast.ResourceLoader.IndexedDBStorage.Connection(params);
    }

};

phast.ResourceLoader.IndexedDBStorage.logPrefix = '[Phast] Resource loader:';

phast.ResourceLoader.IndexedDBStorage.requestToPromise = function (request) {
    return new Promise(function (resolve, reject) {
        request.onsuccess = function () {
            resolve(request.result);
        };
        request.onerror = function () {
            reject(request.error);
        };
    });
};

phast.ResourceLoader.IndexedDBStorage.ConnectionParams = function () {
    this.dbName = 'phastResourcesCache';
    this.dbVersion = 1;
    this.storeName = 'resources';
};

phast.ResourceLoader.IndexedDBStorage.StoredResource = function (token, content) {
    this.token = token;
    this.content = content;
};

phast.ResourceLoader.IndexedDBStorage.Connection = function (params) {

    var logPrefix = phast.ResourceLoader.IndexedDBStorage.logPrefix;
    var r2p = phast.ResourceLoader.IndexedDBStorage.requestToPromise;

    var dbPromise;

    this.get = get;

    this.dropDB = dropDB;

    function get() {
        if (!dbPromise) {
            dbPromise = openDB(params);
        }
        return dbPromise;
    }

    function dropDB() {
        return get().then(function (db) {
            console.error(logPrefix, 'Dropping DB');
            db.close();
            dbPromise = null;
            return r2p(indexedDB.deleteDatabase(params.dbName));
        });
    }

    function openDB(params) {
        if (typeof indexedDB === 'undefined') {
            return Promise.reject(new Error('IndexedDB is not available'));
        }

        var request = indexedDB.open(params.dbName, params.dbVersion);
        request.onupgradeneeded = function (db) {
            createSchema(request.result, params);
        };

        return r2p(request)
            .then(function (db) {
                db.onversionchange = function () {
                    console.debug(logPrefix, 'Closing DB');
                    db.close();
                    if (dbPromise) {
                        dbPromise = null;
                    }
                };
                return db;
            })
            .catch(function (e) {
                console.error(logPrefix, "Error while opening database:", e);
                throw e;
            });
    }

    function createSchema(db, params) {
        db.createObjectStore(params.storeName, {keyPath: 'token'});
    }

};

phast.ResourceLoader.StorageCache = function (params, storage) {

    var StoredResource = phast.ResourceLoader.IndexedDBStorage.StoredResource;

    this.get = function (params) {
        return get(paramsToToken(params));
    };
    this.set = function (params, content) {
        return set(paramsToToken(params), content, false);
    };

    var storageSize = null;

    function paramsToToken(params) {
        return JSON.stringify(params);
    }

    function get(token) {
        return storage.get(token)
            .then(function (item) {
                if (item) {
                    return Promise.resolve(item.content);
                }
                return Promise.resolve();
            });
    }

    function set(token, content, noRetry) {
        return getCurrentStorageSize()
            .then(function (size) {
                var newSize = content.length + size;
                if (newSize > params.maxStorageSize) {
                    return noRetry || content.length > params.maxStorageSize
                        ? Promise.reject(new Error('Storage quota will be exceeded'))
                        : cleanupAndRetrySet(token, content);
                }
                storageSize = newSize;
                var item = new StoredResource(token, content);
                return storage.store(item);
            })
    }

    function cleanupAndRetrySet(token, content) {
        return cleanUp()
            .then(function () {
                return set(token, content, true);
            });
    }

    function cleanUp() {
        return storage.clear()
            .then(function () {
                storageSize = 0;
            });
    }

    function getCurrentStorageSize() {
        if (storageSize !== null) {
            return Promise.resolve(storageSize);
        }
        var size = 0;
        return storage
            .iterateOnAll(function (item) {
                size += item.content.length;
            })
            .then(function () {
                storageSize = size;
                return Promise.resolve(storageSize);
            });
    }

};

phast.ResourceLoader.StorageCache.StorageCacheParams = function () {
    this.maxStorageSize = 4.5 * 1024 * 1024;
};


phast.ResourceLoader.make = function (serviceUrl, shortParamsMappings) {
    var storageParams = new phast.ResourceLoader.IndexedDBStorage.ConnectionParams();
    var storage = new phast.ResourceLoader.IndexedDBStorage(storageParams);
    var cacheParams = new phast.ResourceLoader.StorageCache.StorageCacheParams();
    var cache = new phast.ResourceLoader.StorageCache(cacheParams, storage);
    var client = new phast.ResourceLoader.BundlerServiceClient(serviceUrl, shortParamsMappings);
    return new phast.ResourceLoader(client, cache);
};




