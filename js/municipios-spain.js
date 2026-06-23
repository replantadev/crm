/**
 * CRM Energitel - Sistema de municipios España.
 *
 * Antes este archivo contenía todos los datos hard-coded (solo León completo
 * y capitales para el resto). Desde v1.17 los datos viven en
 *   assets/data/municipios-es.json   (generado desde el diccionario oficial
 *   del INE con tools/build-municipios.ps1)
 *
 * Este script expone una API asíncrona ligera en window.CRM_Municipios:
 *
 *   await CRM_Municipios.load();                 // garantiza datos listos
 *   CRM_Municipios.getProvincias();              // [{ code, name }, ...]  (sync)
 *   CRM_Municipios.esProvinciaValida(name);      // boolean                (sync)
 *   await CRM_Municipios.buscarMunicipios(q, provinciaName, max=10);
 *   await CRM_Municipios.esMunicipioValido(municipio, provinciaName);
 *
 * El JSON se descarga una sola vez en idle time tras DOMContentLoaded para
 * que la primera búsqueda del usuario sea instantánea.
 */
(function () {
    'use strict';

    /**
     * Lista estable de las 52 provincias (códigos INE).
     * Mantener sincronizada con tools/build-municipios.ps1.
     */
    var PROVINCIAS = [
        { code: '01', name: 'Álava' },
        { code: '02', name: 'Albacete' },
        { code: '03', name: 'Alicante' },
        { code: '04', name: 'Almería' },
        { code: '05', name: 'Ávila' },
        { code: '06', name: 'Badajoz' },
        { code: '07', name: 'Illes Balears' },
        { code: '08', name: 'Barcelona' },
        { code: '09', name: 'Burgos' },
        { code: '10', name: 'Cáceres' },
        { code: '11', name: 'Cádiz' },
        { code: '12', name: 'Castellón' },
        { code: '13', name: 'Ciudad Real' },
        { code: '14', name: 'Córdoba' },
        { code: '15', name: 'A Coruña' },
        { code: '16', name: 'Cuenca' },
        { code: '17', name: 'Girona' },
        { code: '18', name: 'Granada' },
        { code: '19', name: 'Guadalajara' },
        { code: '20', name: 'Gipuzkoa' },
        { code: '21', name: 'Huelva' },
        { code: '22', name: 'Huesca' },
        { code: '23', name: 'Jaén' },
        { code: '24', name: 'León' },
        { code: '25', name: 'Lleida' },
        { code: '26', name: 'La Rioja' },
        { code: '27', name: 'Lugo' },
        { code: '28', name: 'Madrid' },
        { code: '29', name: 'Málaga' },
        { code: '30', name: 'Murcia' },
        { code: '31', name: 'Navarra' },
        { code: '32', name: 'Ourense' },
        { code: '33', name: 'Asturias' },
        { code: '34', name: 'Palencia' },
        { code: '35', name: 'Las Palmas' },
        { code: '36', name: 'Pontevedra' },
        { code: '37', name: 'Salamanca' },
        { code: '38', name: 'Santa Cruz de Tenerife' },
        { code: '39', name: 'Cantabria' },
        { code: '40', name: 'Segovia' },
        { code: '41', name: 'Sevilla' },
        { code: '42', name: 'Soria' },
        { code: '43', name: 'Tarragona' },
        { code: '44', name: 'Teruel' },
        { code: '45', name: 'Toledo' },
        { code: '46', name: 'Valencia' },
        { code: '47', name: 'Valladolid' },
        { code: '48', name: 'Bizkaia' },
        { code: '49', name: 'Zamora' },
        { code: '50', name: 'Zaragoza' },
        { code: '51', name: 'Ceuta' },
        { code: '52', name: 'Melilla' }
    ];

    function normalize(s) {
        if (s == null) return '';
        return String(s)
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '') // quitar acentos
            .trim();
    }

    var NAME_TO_CODE = (function () {
        var map = Object.create(null);
        for (var i = 0; i < PROVINCIAS.length; i++) {
            map[normalize(PROVINCIAS[i].name)] = PROVINCIAS[i].code;
        }
        return map;
    })();

    var dataPromise = null;
    var cachedData  = null;

    function getDatasetUrl() {
        if (window.crmMunicipiosData && window.crmMunicipiosData.url) {
            return window.crmMunicipiosData.url;
        }
        // Fallback: best-effort path relativo al plugin si la página no
        // tuvo wp_localize_script. Se asume estructura estándar.
        if (window.crmData && window.crmData.pluginUrl) {
            return window.crmData.pluginUrl.replace(/\/?$/, '/') + 'assets/data/municipios-es.json';
        }
        return null;
    }

    function load() {
        if (cachedData) return Promise.resolve(cachedData);
        if (dataPromise) return dataPromise;

        var url = getDatasetUrl();
        if (!url) {
            return Promise.reject(new Error('No se pudo determinar la URL del bundle de municipios.'));
        }

        dataPromise = fetch(url, { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status + ' al cargar ' + url);
                return res.json();
            })
            .then(function (json) {
                cachedData = json;
                return json;
            })
            .catch(function (err) {
                // Permitir reintentar limpiando la promesa fallida.
                dataPromise = null;
                throw err;
            });

        return dataPromise;
    }

    function getProvincias() {
        // Devolvemos copia para evitar mutaciones externas.
        return PROVINCIAS.slice();
    }

    function esProvinciaValida(name) {
        if (!name) return false;
        return Object.prototype.hasOwnProperty.call(NAME_TO_CODE, normalize(name));
    }

    function codeForProvincia(name) {
        return NAME_TO_CODE[normalize(name)] || null;
    }

    function buscarMunicipios(query, provinciaName, max) {
        max = max || 10;
        var q = normalize(query);
        if (q.length < 1) return Promise.resolve([]);
        var code = codeForProvincia(provinciaName);
        if (!code) return Promise.resolve([]);

        return load().then(function (data) {
            var list = (data && data.municipios && data.municipios[code]) || [];
            var matches = [];
            for (var i = 0; i < list.length && matches.length < max * 4; i++) {
                if (normalize(list[i]).indexOf(q) !== -1) {
                    matches.push(list[i]);
                }
            }
            matches.sort(function (a, b) {
                var aStarts = normalize(a).indexOf(q) === 0;
                var bStarts = normalize(b).indexOf(q) === 0;
                if (aStarts && !bStarts) return -1;
                if (!aStarts && bStarts) return 1;
                return a.localeCompare(b, 'es');
            });
            return matches.slice(0, max);
        });
    }

    function esMunicipioValido(municipio, provinciaName) {
        if (!municipio) return Promise.resolve(false);
        var code = codeForProvincia(provinciaName);
        if (!code) return Promise.resolve(false);
        var target = normalize(municipio);
        return load().then(function (data) {
            var list = (data && data.municipios && data.municipios[code]) || [];
            for (var i = 0; i < list.length; i++) {
                if (normalize(list[i]) === target) return true;
            }
            return false;
        });
    }

    // Precarga diferida para que la primera búsqueda sea instantánea.
    function prefetch() {
        try {
            if ('requestIdleCallback' in window) {
                window.requestIdleCallback(function () { load().catch(function () {}); }, { timeout: 3000 });
            } else {
                setTimeout(function () { load().catch(function () {}); }, 1500);
            }
        } catch (e) { /* no-op */ }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', prefetch, { once: true });
    } else {
        prefetch();
    }

    window.CRM_Municipios = {
        load: load,
        getProvincias: getProvincias,
        esProvinciaValida: esProvinciaValida,
        buscarMunicipios: buscarMunicipios,
        esMunicipioValido: esMunicipioValido,
        // Compat: algunos consumidores antiguos pedían la lista enum:
        get PROVINCIAS_ESPANA() {
            var out = {};
            PROVINCIAS.forEach(function (p) { out[p.name] = p.name; });
            return out;
        }
    };
})();
