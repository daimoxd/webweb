/**
 * ESTADO GLOBAL
 */
const state = {
    cardData: {
        name: '',
        bio: '',
        avatarUrl: 'src/assets/Gato.png', // Avatar por defecto
        color: '#3b82f6'
    }
};

/**
 * REFERENCIAS AL DOM
 */
const dom = {
    inputName: document.getElementById('input-name'),
    inputBio: document.getElementById('input-bio'),
    inputColor: document.getElementById('input-color'),
    form: document.getElementById('profile-form'),
    avatarSelector: document.getElementById('avatar-selector'),
    previewName: document.getElementById('name-preview'),
    previewBio: document.getElementById('bio-preview'),
    previewAvatar: document.getElementById('avatar-preview'),
    previewHeader: document.getElementById('preview-header'),
    viewCreate: document.getElementById('view-create'),
    inputSearch: document.getElementById('github-search'),
    btnFetch: document.getElementById('btn-fetch'),
    apiStatus: document.getElementById('api-status'),
};

/**
 * GENERADOR DEL SELECTOR DE AVATARES
 * Crea los 12 elementos <img> dinámicamente.
 */
const initAvatarSelector = () => {
    dom.avatarSelector.innerHTML = ''; // Evitar duplicados
    const avatares = [
        'CaraFeliz_xbox360.png', 'ChinesePanda.png', 'ClubPenguin.png',
        'Fletcher.png', 'Gato.png', 'HideoKojima.png',
        'JackFrost.png', 'Kagami.png', 'KratosUtorrent.png',
        'Megumin.png', 'NecoArc.png', 'panda_xbox360.png',
        'perro_xbox360.png', 'WittyAlien.png'
    ];
    for (const archivo of avatares) {
        const rutaImagen = `src/assets/${archivo}`;
        const img = document.createElement('img');
        img.src = rutaImagen;
        img.alt = archivo.replace('.png', '');
        img.className = 'avatar-option';
        // Guardamos la ruta en un atributo de datos para leerlo fácilmente
        img.dataset.url = rutaImagen;

        // Evento: Al hacer clic en una miniatura, actualizamos el estado global
        img.addEventListener('click', () => {
            state.cardData.avatarUrl = rutaImagen;
            renderCard(); // Forzamos la actualización de la UI
        });

        dom.avatarSelector.appendChild(img); // Insertar la imagen en el contenedor
    }
};

/**
 * FUNCIÓN DE RENDERIZADO (Reactividad)
 */
const renderCard = () => {
    dom.previewName.textContent = state.cardData.name || 'Nombre Apellido';
    dom.previewBio.textContent = state.cardData.bio || 'La biografía aparecerá aquí...';
    dom.previewAvatar.src = state.cardData.avatarUrl;
    dom.previewHeader.style.backgroundColor = state.cardData.color;
    dom.inputName.value = state.cardData.name;
    dom.inputBio.value = state.cardData.bio;
    dom.inputColor.value = state.cardData.color;
    // Actualizar visualmente qué avatar está seleccionado en la cuadrícula
    const avatares = dom.avatarSelector.querySelectorAll('.avatar-option');
    avatares.forEach(img => {
        if (img.dataset.url === state.cardData.avatarUrl) {
            img.classList.add('selected');
        } else {
            img.classList.remove('selected');
        }
    });
};

/**
 * EVENTOS MANUALES
 */
const setupManualEvents = () => {
    dom.inputName.addEventListener('input', (e) => {
        state.cardData.name = e.target.value; // Guardar nombre
        renderCard(); // Actualizar tarjeta
    });

    dom.inputBio.addEventListener('input', (e) => {
        state.cardData.bio = e.target.value;
        renderCard();
    });

    dom.inputColor.addEventListener('input', (e) => {
        state.cardData.color = e.target.value; // Guardar color
        renderCard();
    });

    dom.form.addEventListener('submit', (e) => {
        e.preventDefault(); // Evitar recargar la página al enviar el formulario
    });
};

/**
 * CONSUMO DE API Github
 */
const fetchGitHubData = async (username) => {
    if (!username) return;

    dom.apiStatus.textContent = 'Buscando usuario en GitHub...';
    dom.apiStatus.className = 'status-msg status-loading';
    dom.btnFetch.disabled = true;

    try {
        const response = await fetch(`https://api.github.com/users/${username}`);

        if (!response.ok) throw new Error('Usuario no encontrado');

        const data = await response.json();

        state.cardData.name = data.name || data.login;
        state.cardData.bio = data.bio || 'Este usuario no tiene biografía pública.';
        state.cardData.avatarUrl = data.avatar_url;

        renderCard();

        dom.apiStatus.textContent = '¡Datos cargados correctamente!';
        dom.apiStatus.className = 'status-msg status-success';

    } catch (error) {
        dom.apiStatus.textContent = error.message;
        dom.apiStatus.className = 'status-msg status-error';
    } finally {
        dom.btnFetch.disabled = false;
        setTimeout(() => dom.apiStatus.textContent = '', 3000);
    }
};

/**
 * INICIALIZACIÓN DE LA APLICACIÓN
 */
document.addEventListener('DOMContentLoaded', () => {
    initAvatarSelector(); // Inicializamos la cuadrícula de imágenes
    setupManualEvents();

    dom.btnFetch.addEventListener('click', () => {
        const username = dom.inputSearch.value.trim();
        fetchGitHubData(username);
    });

    dom.inputSearch.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            dom.btnFetch.click();
        }
    });

    renderCard();
});
