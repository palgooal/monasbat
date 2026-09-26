(function (root, factory) {
    var api = factory(root);
    if (typeof module === 'object' && module.exports) module.exports = api;
    root.PGEManualActivationOperation = api;
}(typeof window !== 'undefined' ? window : globalThis, function (root) {
    'use strict';
    var storageKey = 'pge_manual_activation_operation';
    function uuid() {
        if (root.crypto && typeof root.crypto.randomUUID === 'function') return root.crypto.randomUUID();
        if (!root.crypto || typeof root.crypto.getRandomValues !== 'function') throw new Error('secure_uuid_unavailable');
        var bytes = new Uint8Array(16); root.crypto.getRandomValues(bytes);
        bytes[6]=(bytes[6]&15)|64; bytes[8]=(bytes[8]&63)|128;
        var hex=Array.prototype.map.call(bytes,function(b){return b.toString(16).padStart(2,'0');}).join('');
        return hex.slice(0,8)+'-'+hex.slice(8,12)+'-'+hex.slice(12,16)+'-'+hex.slice(16,20)+'-'+hex.slice(20);
    }
    function read() { try { return JSON.parse(root.sessionStorage.getItem(storageKey)||'null'); } catch(e) { return null; } }
    function getOrCreate(fingerprint) {
        var stored=read();
        if (stored && stored.fingerprint===fingerprint && typeof stored.id==='string') return stored.id;
        var id=uuid(); root.sessionStorage.setItem(storageKey,JSON.stringify({id:id,fingerprint:fingerprint})); return id;
    }
    function complete(fingerprint) { var stored=read(); if (!fingerprint || (stored&&stored.fingerprint===fingerprint)) root.sessionStorage.removeItem(storageKey); }
    return { getOrCreate:getOrCreate, complete:complete, storageKey:storageKey };
}));
