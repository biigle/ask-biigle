# Who developed Biigle 2.0?
- BIIGLE 2.0 (Bio-Image Indexing and Graphical Labelling Environment) was developed by researchers in the Biodata Mining Group at Bielefeld University in Germany, led by Prof. Dr. Tim W. Nattkemper. The primary software architects and lead developers behind the release and reimplementation of BIIGLE 2.0 are Martin Zurowietz, Daniel Langenkämper and Tim W. Nattkemper.


# Does Biigle have a YOLO export? How to export Yolo data?
- Biigle does not have a YOLO export
- Conversion to YOLO format can be easily performed from the Biigle provided COCO output and an image directory using the ultralytics package and the following code snippet [Ultralytics docs](https://docs.ultralytics.com/reference/data/converter#ultralytics.data.converter.convert_coco):
```
from ultralytics.data.converter import convert_coco

convert_coco(
    labels_dir="path/to/coco/annotations/" 
    save_dir="path/to/output/yolo/", 
    cls91to80=False,
)
```
- the `labels_dir` is the directory that contains the coco.json file not the path to coco.json,
- `save_dir` will contain a folder (labels) with the converted annotations, you still have to copy the images to the images folder though


# Is Biigle currently offline?
- You can check the status of https://biigle.de at https://status.biigle.de/

# How does BIIGLE 2.0 handle gigapixel image rendering

BIIGLE 2.0 handles gigapixel imagery (such as seafloor mosaics, aerial drone mapping, or digital microscopy virtual slides) through an automated **image pyramid and tiling pipeline** designed to deliver smooth web rendering without overloading the browser's memory.

## 1. Automated Server-Side Preprocessing

When an image exceeding standard dimensions is uploaded, BIIGLE automatically flags it as a gigapixel file and initiates an asynchronous preprocessing task:

* **Pyramid Level Generation:** The server builds a multi-resolution image pyramid, generating multiple downsampled versions (mipmaps) of the original full-resolution dataset.
* **Tile Extraction:** At each pyramid resolution layer, the image is sliced into a structured grid of smaller, uniformly sized image "tiles" (e.g., $256 \times 256$ or $512 \times 512$ pixels).
* **Storage & Indexing:** These tiles are indexed by zoom level and spatial coordinate $(z, x, y)$ for fast retrieval via the application backend.

## 2. Dynamic Client-Side Rendering

Rather than transmitting gigabytes of raw pixel data, the front-end image viewer loads only what is visible within the user's current viewport:

* **Viewport Tiling:** The viewer calculates the user's current bounding box and zoom level, requesting only the specific tiles needed to fill the screen.
* **Progressive Loading:** As the user pans or zooms, lower-resolution tiles render instantly as placeholders while high-resolution tiles load in the background, minimizing latency.
* **Coordinate Mapping:** Annotation spatial coordinates (points, lines, polygons) are stored relative to the full-resolution master canvas and mapped dynamically onto the current viewer coordinates.

## 3. Web-Based Interface & Navigation

To maintain usability when viewing large mosaics, BIIGLE integrates several UI helpers:

* **Minimap Overview:** A contextual thumbnail overlay in the corner shows the overall extent of the image mosaic and highlights the user's active viewport.
* **Smooth Zooming & Panning:** Continuous WebGL/Canvas or HTML5-driven zooming enables rapid inspection across orders of magnitude of scale—from multi-meter seafloor stretches down to sub-millimeter biological features.


# Can BIIGLE 2.0 be deployed on offshore research vessels without an active internet connection?

**Yes, absolutely.**

Although BIIGLE 2.0 is designed as a web-based application (accessed via a web browser), it **does not require an active satellite or internet connection** to operate when deployed offshore.

### How Offline Deployment Works

1. **Local Server Deployment:** BIIGLE 2.0 can be installed locally on a server, workstation, or mobile compute cluster (such as a *Sea-going High-Performance Compute Cluster* / SHiPCC) directly on board the research vessel.
2. **Local Area Network (LAN):** Once the local server is running, scientists connect their laptops to the vessel’s internal network (via Wi-Fi or Ethernet).
3. **Browser Access:** Researchers access the BIIGLE instance through a standard web browser pointing to the ship's local server IP/hostname.

This allows full collaborative image/video annotation, taxonomy tagging, and data processing while completely disconnected from the open internet.

