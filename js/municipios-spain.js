/**
 * Sistema completo de provincias y municipios de España
 * CRM Plugin - Sistema robusto de localización
 */

// 50 provincias oficiales de España
const PROVINCIAS_ESPANA = {
    'Álava': 'Álava',
    'Albacete': 'Albacete', 
    'Alicante': 'Alicante',
    'Almería': 'Almería',
    'Asturias': 'Asturias',
    'Ávila': 'Ávila',
    'Badajoz': 'Badajoz',
    'Barcelona': 'Barcelona',
    'Burgos': 'Burgos',
    'Cáceres': 'Cáceres',
    'Cádiz': 'Cádiz',
    'Cantabria': 'Cantabria',
    'Castellón': 'Castellón',
    'Ciudad Real': 'Ciudad Real',
    'Córdoba': 'Córdoba',
    'Cuenca': 'Cuenca',
    'Girona': 'Girona',
    'Granada': 'Granada',
    'Guadalajara': 'Guadalajara',
    'Guipúzcoa': 'Guipúzcoa',
    'Huelva': 'Huelva',
    'Huesca': 'Huesca',
    'Islas Baleares': 'Islas Baleares',
    'Jaén': 'Jaén',
    'La Coruña': 'La Coruña',
    'La Rioja': 'La Rioja',
    'Las Palmas': 'Las Palmas',
    'León': 'León',
    'Lleida': 'Lleida',
    'Lugo': 'Lugo',
    'Madrid': 'Madrid',
    'Málaga': 'Málaga',
    'Murcia': 'Murcia',
    'Navarra': 'Navarra',
    'Orense': 'Orense',
    'Palencia': 'Palencia',
    'Pontevedra': 'Pontevedra',
    'Salamanca': 'Salamanca',
    'Santa Cruz de Tenerife': 'Santa Cruz de Tenerife',
    'Segovia': 'Segovia',
    'Sevilla': 'Sevilla',
    'Soria': 'Soria',
    'Tarragona': 'Tarragona',
    'Teruel': 'Teruel',
    'Toledo': 'Toledo',
    'Valencia': 'Valencia',
    'Valladolid': 'Valladolid',
    'Vizcaya': 'Vizcaya',
    'Zamora': 'Zamora',
    'Zaragoza': 'Zaragoza'
};

// Principales municipios por provincia (muestra representativa)
const MUNICIPIOS_POR_PROVINCIA = {
    'León': [
        'León', 'Ponferrada', 'San Andrés del Rabanedo', 'Villaquilambre', 'Astorga',
        'La Bañeza', 'Valencia de Don Juan', 'Sahagún', 'Villablino', 'Bembibre',
        'Cacabelos', 'Toral de los Guzmanes', 'Mansilla de las Mulas', 'Boñar',
        'Riaño', 'Puente de Domingo Flórez', 'Villafranca del Bierzo', 'Cistierna',
        'La Robla', 'Santa María del Monte de Cea', 'Gradefes', 'Carrizo',
        'Benavides', 'Quintana del Castillo', 'Santas Martas', 'Chozas de Abajo',
        'Valverde de la Virgen', 'Cuadros', 'Sariegos', 'Carrocera'
    ],
    'Madrid': [
        'Madrid', 'Móstoles', 'Alcalá de Henares', 'Fuenlabrada', 'Leganés',
        'Getafe', 'Alcorcón', 'Torrejón de Ardoz', 'Parla', 'Alcobendas',
        'Las Rozas de Madrid', 'San Sebastián de los Reyes', 'Pozuelo de Alarcón',
        'Rivas-Vaciamadrid', 'Majadahonda', 'Coslada', 'Valdemoro', 'Aranjuez',
        'Collado Villalba', 'Arganda del Rey', 'Pinto', 'Colmenar Viejo',
        'San Lorenzo de El Escorial', 'Boadilla del Monte', 'Tres Cantos'
    ],
    'Barcelona': [
        'Barcelona', 'L\'Hospitalet de Llobregat', 'Badalona', 'Terrassa', 'Sabadell',
        'Mataró', 'Santa Coloma de Gramenet', 'Cornellà de Llobregat', 'Sant Boi de Llobregat',
        'Manresa', 'Reus', 'Rubí', 'Vilanova i la Geltrú', 'Viladecans', 'El Prat de Llobregat',
        'Castelldefels', 'Granollers', 'Cerdanyola del Vallès', 'Mollet del Vallès',
        'Sant Cugat del Vallès', 'Esplugues de Llobregat', 'Gavà', 'Vic'
    ],
    'Valencia': [
        'Valencia', 'Alicante', 'Elche', 'Castellón de la Plana', 'Torrevieja',
        'Orihuela', 'Gandía', 'Sagunto', 'Alcoy', 'Paterna', 'Alzira',
        'Sant Vicent del Raspeig', 'Elda', 'Benidorm', 'Cullera', 'Xàtiva',
        'Dénia', 'Villena', 'Torrent', 'Burriana', 'Crevillent', 'Sueca'
    ],
    'Sevilla': [
        'Sevilla', 'Dos Hermanas', 'Alcalá de Guadaíra', 'Utrera', 'Mairena del Aljarafe',
        'Écija', 'La Rinconada', 'Los Palacios y Villafranca', 'Carmona', 'Lebrija',
        'Coria del Río', 'Tomares', 'Bormujos', 'San Juan de Aznalfarache', 'Marchena',
        'Morón de la Frontera', 'Gelves', 'Castilleja de la Cuesta', 'Osuna'
    ],
    'Málaga': [
        'Málaga', 'Marbella', 'Jerez de la Frontera', 'Algeciras', 'Fuengirola',
        'Mijas', 'Torremolinos', 'Estepona', 'Benalmádena', 'Ronda', 'Antequera',
        'Vélez-Málaga', 'Cádiz', 'San Fernando', 'El Puerto de Santa María',
        'Chiclana de la Frontera', 'La Línea de la Concepción', 'Sanlúcar de Barrameda'
    ],
    'Las Palmas': [
        'Las Palmas de Gran Canaria', 'Telde', 'Santa Lucía de Tirajana', 'Arucas',
        'Ingenio', 'Gáldar', 'Vecindario', 'Agüimes', 'Puerto del Rosario',
        'Corralejo', 'Antigua', 'Pájara', 'Tuineje', 'Betancuria', 'La Oliva'
    ],
    'Santa Cruz de Tenerife': [
        'Santa Cruz de Tenerife', 'San Cristóbal de La Laguna', 'Arona',
        'Adeje', 'Granadilla de Abona', 'Puerto de la Cruz', 'Los Realejos',
        'La Orotava', 'Tacoronte', 'Icod de los Vinos', 'Güímar', 'Candelaria',
        'Santiago del Teide', 'El Sauzal', 'Garachico'
    ],
    'Vizcaya': [
        'Bilbao', 'Getxo', 'Portugalete', 'Santurtzi', 'Basauri', 'Leioa',
        'Barakaldo', 'Sestao', 'Durango', 'Bermeo', 'Gernika-Lumo', 'Mungia',
        'Erandio', 'Galdakao', 'Amorebieta-Etxano', 'Berriz'
    ],
    'La Coruña': [
        'A Coruña', 'Vigo', 'Santiago de Compostela', 'Ourense', 'Lugo',
        'Pontevedra', 'Ferrol', 'Narón', 'Oleiros', 'Arteixo', 'Culleredo',
        'Carballo', 'Redondela', 'Cangas', 'Marín', 'Vilagarcía de Arousa'
    ],
    'Asturias': [
        'Oviedo', 'Gijón', 'Avilés', 'Siero', 'Langreo', 'Mieres',
        'Castrillón', 'Llanera', 'Corvera de Asturias', 'Villaviciosa',
        'Llanes', 'Cangas de Onís', 'Ribadesella', 'Navia'
    ],
    'Murcia': [
        'Murcia', 'Cartagena', 'Lorca', 'Molina de Segura', 'Alcantarilla',
        'Águilas', 'Cieza', 'Yecla', 'San Javier', 'Totana', 'Caravaca de la Cruz',
        'Jumilla', 'Mazarrón', 'San Pedro del Pinatar', 'Alhama de Murcia'
    ],
    'Alicante': [
        'Alicante', 'Elche', 'Torrevieja', 'Orihuela', 'Benidorm', 'Alcoy',
        'Sant Vicent del Raspeig', 'Elda', 'Dénia', 'Villena', 'Crevillent',
        'Altea', 'Calpe', 'Jávea', 'Villajoyosa', 'Santa Pola', 'Guardamar del Segura'
    ]
    // Se pueden añadir más provincias según necesidad
};

/**
 * Busca municipios por provincia y query de texto
 */
function buscarMunicipios(query, provincia) {
    if (!provincia || !MUNICIPIOS_POR_PROVINCIA[provincia]) {
        return [];
    }
    
    const municipios = MUNICIPIOS_POR_PROVINCIA[provincia];
    const queryLower = query.toLowerCase().trim();
    
    if (queryLower.length < 2) {
        return [];
    }
    
    return municipios
        .filter(municipio => 
            municipio.toLowerCase().includes(queryLower)
        )
        .slice(0, 10) // Limitar a 10 resultados
        .sort((a, b) => {
            // Priorizar coincidencias que empiecen con el query
            const aStarts = a.toLowerCase().startsWith(queryLower);
            const bStarts = b.toLowerCase().startsWith(queryLower);
            
            if (aStarts && !bStarts) return -1;
            if (!aStarts && bStarts) return 1;
            return a.localeCompare(b);
        });
}

/**
 * Obtiene todas las provincias ordenadas alfabéticamente
 */
function obtenerProvincias() {
    return Object.values(PROVINCIAS_ESPANA).sort((a, b) => a.localeCompare(b));
}

/**
 * Valida si una provincia es válida
 */
function esProvinciaValida(provincia) {
    return Object.values(PROVINCIAS_ESPANA).includes(provincia);
}

/**
 * Valida si un municipio existe en la provincia dada
 */
function esMunicipioValido(municipio, provincia) {
    if (!provincia || !MUNICIPIOS_POR_PROVINCIA[provincia]) {
        return false;
    }
    
    return MUNICIPIOS_POR_PROVINCIA[provincia]
        .some(m => m.toLowerCase() === municipio.toLowerCase());
}

// Exportar funciones para uso global
window.CRM_Municipios = {
    buscarMunicipios,
    obtenerProvincias,
    esProvinciaValida,
    esMunicipioValido,
    PROVINCIAS_ESPANA,
    MUNICIPIOS_POR_PROVINCIA
};
