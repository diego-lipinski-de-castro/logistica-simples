<script setup>
import { Loader } from '@googlemaps/js-api-loader';
import { onMounted, ref } from 'vue';
import L from 'leaflet';
import "leaflet/dist/leaflet.css";
import "leaflet.gridlayer.googlemutant";
import "leaflet-draw/dist/leaflet.draw";
import "leaflet-draw/dist/leaflet.draw.css";
import homeIcon from "@/assets/home-icon.svg";
import plusIcon from "@/assets/plus-icon.svg";
import minusIcon from "@/assets/minus-icon.svg";

import { useStorage } from '@vueuse/core'

// simulate saving to database
const userData = useStorage('userData', {
  area: [[[-48.566093,-27.587263],[-48.550816,-27.583155],[-48.540344,-27.567169],[-48.459993,-27.544188],[-48.50996,-27.597288],[-48.484376,-27.60993],[-48.527989,-27.643399],[-48.532448,-27.622382],[-48.545666,-27.61858],[-48.56266,-27.602003],[-48.566093,-27.587263]]],
  radiuses: [],
})

const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

let googleLoaderInitialized = false;

const googleLoader = new Loader({
  apiKey: import.meta.env.VITE_GOOGLE_API_KEY,
  version: '3.58',
  libraries: ['places']
})

const useGoogleTiles = false

const circleOptions = {
  stroke: true,
  fill: true,
  fillOpacity: 0.0,
  fillColor: "#DC2626",
  color: "#000",
  weight: 1,
  opacity: 0.5,
  dashArray: "5, 5",
  dashOffset: "0",
};

// controls
let map = null
let drawControl = null;
let zoomControl = null;

// layers
let editableLayer = new L.FeatureGroup()
let polygonLayer = ref(null);
let drawLayer = ref(null);

let circles = [];

// Florianopolis
let center = [-27.593500, -48.558540]

// 
const isEditingArea = ref(false)

const tab = ref(1)

const initGoogle = async () => {
  // make sure we do not reload while in development
  if (googleLoaderInitialized) return;
  googleLoaderInitialized = true;

  // required if useGoogleTiles is true
  await googleLoader.importLibrary('maps')

  initMap();
}

const initMap = async () => {
  map = L.map('map', {
    zoomControl: false,
    scrollWheelZoom: true,
    touchZoom: false,
    dragging: true,
    keyboard: false,
    fullscreenControl: !isSafari,
  }).setView(center, 13)

  if (useGoogleTiles) {
    L.gridLayer
      .googleMutant({
        type: "roadmap",
      })
      .addTo(map);
  } else {

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);
  }

  L.marker(center)
    .addTo(map);

  if (userData.value.area) {
    loadUserArea()
  } else {
    map.addLayer(editableLayer);

    map.on("draw:created", (ev) => {
      const type = ev.layerType;
      const layer = ev.layer;

      if (type === "polygon") {
        editableLayer.addLayer(layer);
        drawLayer.value = layer;
      }
    });
  }

  addZoomControl()
}

const addZoomControl = () => {
  L.Control.controls = L.Control.extend({
    options: {
      position: "topright",
      zoomInText: `<img src="${plusIcon}"/>`,
      zoomInTitle: "Ampliar",
      zoomOutText: `<img src="${minusIcon}"/>`,
      zoomOutTitle: "Reduzir",
      zoomHomeText: `<img src="${homeIcon}"/>`,
      zoomHomeTitle: "Recentralizar",
    },

    onAdd: function (map) {
      var controlName = "gin-control-zoom",
        container = L.DomUtil.create(
          "div",
          controlName + " leaflet-bar"
        ),
        options = this.options;

      this._zoomInButton = this._createButton(
        options.zoomInText,
        options.zoomInTitle,
        controlName + "-in",
        container,
        this._zoomIn
      );

      this._zoomOutButton = this._createButton(
        options.zoomOutText,
        options.zoomOutTitle,
        controlName + "-out",
        container,
        this._zoomOut
      );

      this._zoomHomeButton = this._createButton(
        options.zoomHomeText,
        options.zoomHomeTitle,
        controlName + "-home",
        container,
        this._zoomHome
      );

      this._updateDisabled();
      map.on("zoomend zoomlevelschange", this._updateDisabled, this);

      return container;
    },

    onRemove: function (map) {
      map.off("zoomend zoomlevelschange", this._updateDisabled, this);
    },

    _zoomIn: function (e) {
      this._map.zoomIn(e.shiftKey ? 3 : 1);
    },

    _zoomOut: function (e) {
      this._map.zoomOut(e.shiftKey ? 3 : 1);
    },

    _zoomHome: function (e) {
      alignCamera()
    },

    _createButton: function (html, title, className, container, fn) {
      var link = L.DomUtil.create("a", className, container);
      link.innerHTML = html;
      link.href = "#";
      link.title = title;

      L.DomEvent.on(
        link,
        "mousedown dblclick",
        L.DomEvent.stopPropagation
      )
        .on(link, "click", L.DomEvent.stop)
        .on(link, "click", fn, this)
        .on(link, "click", this._refocusOnMap, this);

      return link;
    },

    _updateDisabled: function () {
      var map = this._map,
        className = "leaflet-disabled";

      L.DomUtil.removeClass(this._zoomInButton, className);
      L.DomUtil.removeClass(this._zoomOutButton, className);

      if (map._zoom === map.getMinZoom()) {
        L.DomUtil.addClass(this._zoomOutButton, className);
      }
      if (map._zoom === map.getMaxZoom()) {
        L.DomUtil.addClass(this._zoomInButton, className);
      }
    },
  });

  zoomControl = new L.Control.controls();
  zoomControl.addTo(map);
}

const alignCamera = () => {
  if (circles.length > 0) {
    map.fitBounds(circles[0].getBounds(), {
      padding: [10, 10],
    });
  } else if (polygonLayer.value) {
    map.fitBounds(polygonLayer.value.getBounds());
  } else {
    map.setView(new L.LatLng(center[0] + offset, center[1]), 13);
  }
}

const loadUserArea = () => {
  // reverse lat, lng
  const points = userData.value.area[0].map(
    (point) => [point[1], point[0]]
  );

  polygonLayer.value = new L.polygon([points], {
    color: "#DC2626",
  });

  polygonLayer.value.on("edit", (e) => {
    drawLayer.value = e.target;
  });

  map.addLayer(polygonLayer.value);

  updateUserRadiuses();
  drawRadiuses();
}

const drawRadiuses = () => {
  circles.forEach((c) => c.remove());
  circles = []

  // lose reference
  const radiuses = JSON.parse(JSON.stringify(userData.value.radiuses));

  // add backwards because zindex
  radiuses.reverse().forEach(radius => {
    const circle = L.circle(center, {
      ...circleOptions,
      radius: radius.rad * 1000,
    })
    .on("mouseover", function () {
        const el = document.querySelectorAll(
            `[data-polygon="${radius.rad}"]`
        );

        if (el.length == 1) {
            el[0].scrollIntoViewIfNeeded();
            el[0].classList.add(
                "bg-gray-100",
            );
            el[0].classList.remove(
                "bg-white",
            );
        }

        this.setStyle({
            fillOpacity: 0.2,
        });
    })
    .on("mouseout", function () {
        const el = document.querySelectorAll(
            `[data-polygon="${radius.rad}"]`
        );

        if (el.length == 1) {
            el[0].classList.add(
                "bg-white",
            );
            el[0].classList.remove(
                "bg-gray-100",
            );
        }

        this.setStyle({
            fillOpacity: 0.0,
        });
    })
      .addTo(map);

    circles.push(circle);
  })

  alignCamera()
}

const paintPolygon = (event, index, radius) => {
  if (radius == null) return;

  const polygon = circles.find(
    (c) => c.getRadius() === radius * 1000
  );

  if (polygon) {
    polygon.setStyle({
      fillOpacity: 0.2,
    });
  }
};

const unpaintPolygon = (event, index, radius) => {
  if (radius == null) return;

  const polygon = circles.find(
    (c) => c.getRadius() === radius * 1000
  );

  if (polygon) {
    polygon.setStyle({
      fillOpacity: 0.0,
    });
  }
};

const editArea = () => {
  isEditingArea.value = true;

  if (polygonLayer.value) {
    polygonLayer.value.editing.enable()
  } else {
    drawControl = new L.Draw.Polygon(map, {
      shapeOptions: {
        color: "#DC2626",
      },
    })

    drawControl.enable();
  }
}

const finishEditing = (save) => {
  isEditingArea.value = false;

  if (polygonLayer.value) {
    polygonLayer.value.editing.disable();
  }

  if (drawLayer.value === null) {
    // Display popup saying the user needs to draw a polygon before saving
    // Disable save button if the user has not draw anything?
    return;
  }

  // save to database, <lat, lng>[]
  // console.log(drawLayer.value.toGeoJSON().geometry.coordinates)
  userData.value.area = drawLayer.value.toGeoJSON().geometry.coordinates

  updateUserRadiuses()
}

const updateUserRadiuses = () => {
  // here we gonna fake,
  // we calculate the radius in a straight line
  // as if the earth was plane
  // this will give the wrong results

  // the real calculation of max range should be done in the backend
  // postgres + postgis database as shown on the User.php file using the getMaxRadius method

  const maxRadius = 12 // 12km

  const radiusRange = Array.from({ length: maxRadius }, (_, i) => i + 1);

  const mockRadiuses = radiusRange.map((range) => ({
    rad: range,
  }));

  userData.value.radiuses = mockRadiuses;
}

// init
onMounted(() => {
  initGoogle()
})
</script>

<template>
  <div class="min-h-screen bg-gray-100">

    <header class="bg-white shadow">
      <div class="max-w-full mx-auto py-6 px-4 sm:px-6 lg:px-8">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
          Logística simples
        </h2>
      </div>
    </header>

    <main class="max-w-full mx-auto px-4 sm:px-6 lg:px-8">

      <div class="py-12">
        <div class="relative bg-white overflow-hidden shadow sm:rounded-lg">
          <div id="map" style="height: 84vh" />

          <div style="max-height: calc(84vh - 2.5rem); z-index: 99999" class="absolute m-5 top-0 left-0">
            <div class="border border-gray-300 rounded-md shadow-sm">
              <div class="rounded-tl-md rounded-tr-md bg-gray-100 px-6 py-5 border-b border-gray-300">
                <h3 class="font-bold text-sm text-gray-700">
                  Logística simples
                </h3>
              </div>

              <div class="bg-white px-6 border-b border-gray-200">
                <nav class="-mb-px flex space-x-8">
                  <button :class="[
                    'whitespace-nowrap py-4 px-1 border-b-2 font-bold',
                    {
                      'border-transparent text-gray-500':
                        tab != 1,
                      'border-red-500 text-red-600':
                        tab == 1,
                    },
                  ]" @click="tab = 1">
                    Área de entrega
                  </button>

                  <button :disabled="isEditingArea || !userData.area" :class="[
                    'whitespace-nowrap py-4 px-1 border-b-2 font-bold disabled:text-gray-300 disabled:cursor-not-allowed',
                    {
                      'border-transparent text-gray-500':
                        tab != 2,
                      'border-red-500 text-red-600':
                        tab == 2,
                    },
                  ]" @click="tab = 2">
                    Taxas e tempo
                  </button>
                </nav>
              </div>

              <div v-if="tab == 1" class="flex flex-col rounded-bl-md rounded-br-md bg-white px-6 py-5">
                <button v-if="!isEditingArea" type="button"
                  class="inline-flex justify-center items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none"
                  @click="editArea">
                  Editar área de entrega
                </button>

                <span v-else
                  class="inline-flex justify-center items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-700">
                  Editando área de entrega
                </span>

                <button v-if="isEditingArea" :disabled="drawLayer === null" type="button"
                  class="mt-4 inline-flex justify-center items-center px-3 py-2 border text-sm leading-4 font-medium rounded-md text-red-600 hover:text-red-700 focus:outline-none disabled:opacity-50 disabled:text-gray-300 disabled:cursor-not-allowed"
                  @click="finishEditing(true)">
                  Salvar
                </button>

                <button v-if="isEditingArea" type="button"
                  class="mt-2 inline-flex justify-center items-center px-3 py-2 border text-sm leading-4 font-medium rounded-md text-gray-600 hover:text-gray-700 focus:outline-none"
                  @click="finishEditing(false)">
                  Cancelar
                </button>
              </div>

              <div v-if="tab == 2" class="relative flex flex-col rounded-bl-md rounded-br-md bg-white overflow-hidden">

                <div class="overflow-y-scroll" style="height: calc(50vh - 56px);">
                  <table class="border-collapse">
                    <thead class="bg-gray-50">
                      <tr>
                        <th
                          class="pt-4 pb-3 px-2.5 pl-12 text-sm font-bold text-gray-700 sticky top-0 z-10 bg-gray-50 bg-opacity-75 backdrop-blur backdrop-filter border-none border-0">
                          Alcance
                        </th>
                        <th
                          class="pt-4 pb-3 px-2.5 text-sm text-gray-700 sticky top-0 z-10 bg-gray-50 bg-opacity-75 backdrop-blur backdrop-filter border-none border-0">
                          <span class="font-bold">Tempo</span>&nbsp;<span class="font-normal">(mins)</span>
                        </th>
                        <th
                          class="pt-4 pb-3 px-2.5 text-sm font-bold text-gray-700 sticky top-0 z-10 bg-gray-50 bg-opacity-75 backdrop-blur backdrop-filter border-none border-0">
                          Valor entregador
                        </th>
                        <th
                          class="pt-4 pb-3 px-2.5 text-sm font-bold text-gray-700 sticky top-0 z-10 bg-gray-50 bg-opacity-75 backdrop-blur backdrop-filter border-none border-0">
                          Valor cobrado da loja
                        </th>
                        <th
                          class="pt-4 pb-3 px-2.5 pr-12 text-sm font-bold text-gray-700 sticky top-0 z-10 bg-gray-50 bg-opacity-75 backdrop-blur backdrop-filter border-none border-0">
                          Markup
                        </th>
                      </tr>
                    </thead>

                    <tbody class="bg-white">
                      <tr v-for="(radius, index) in userData.radiuses" :key="index"
                        :class="[
                          (index === 0) ? 'border-t' : 'border-t',
                          (index === userData.radiuses.length - 1) ? 'border-b-2' : 'border-b',
                          'hover:bg-gray-100'
                        ]" @mouseenter="
                          paintPolygon($event, index, radius.rad)
                          " @mouseleave="
                            unpaintPolygon($event, index, radius.rad)
                            " :data-polygon="radius.rad">
                        <td class="py-2 px-2.5 pl-12 whitespace-nowrap text-sm text-center text-gray-900">
                          <div class="flex justify-center">
                            <span v-if="radius.rad == null">km adicional</span>
                            <span v-else>Até {{ radius.rad }}km</span>
                          </div>
                        </td>

                        <td class="py-2 px-2.5">
                          <div class="flex justify-center">
                            <input v-model="radius.time" type="number"
                              class="p-1 w-20 shadow-sm focus:ring-red-500 focus:border-red-500 block text-center sm:text-sm border border-gray-300 rounded-md">
                          </div>
                        </td>

                        <td class="py-2 px-2.5">
                          <div class="flex justify-center">
                            <input type="text"
                              class="p-1 w-20 shadow-sm focus:ring-red-500 focus:border-red-500 block text-center sm:text-sm border border-gray-300 rounded-md">
                          </div>
                        </td>

                        <td class="py-2 px-2.5">
                          <div class="flex justify-center">
                            <input type="text"
                              class="p-1 w-20 shadow-sm focus:ring-red-500 focus:border-red-500 block text-center sm:text-sm border border-gray-300 rounded-md">
                          </div>
                        </td>

                        <td class="py-2 px-2.5 pr-12">
                          <div class="flex justify-center">
                            <span>
                              
                            </span>
                          </div>
                        </td>
                      </tr>
                    </tbody>

                    <tfoot v-show="true" class="bg-gray-50">
                      <tr>
                        <th colspan="5"
                          class="py-3 px-2.5 text-sm font-bold text-gray-700 sticky bottom-0 z-10 bg-gray-50 bg-opacity-75 backdrop-blur backdrop-filter">
                          <div class="flex space-x-2.5">
                            <button type="button"
                              class="w-full inline-flex justify-center items-center px-3 py-2 border border-gray-200 text-sm leading-4 font-medium rounded-md text-gray-600 hover:text-gray-700 focus:outline-none">
                              Cancelar
                            </button>

                            <button type="button"
                              class="w-full inline-flex justify-center items-center px-3 py-2 border border-gray-200 text-sm leading-4 font-medium rounded-md text-red-600 hover:text-red-700 focus:outline-none">
                              Salvar
                            </button>
                          </div>
                        </th>
                      </tr>
                    </tfoot>
                  </table>
                </div>

              </div>
            </div>
          </div>
        </div>
      </div>

    </main>
  </div>
</template>