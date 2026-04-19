FROM python:3.10-slim

WORKDIR /app

RUN apt-get update && apt-get install -y \
    libgl1 \
    libglib2.0-0 \
    gcc \
    tesseract-ocr \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir --upgrade pip
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

ENV PARKY_TF_SERVICE_HOST=0.0.0.0
ENV PARKY_TF_SERVICE_PORT=8765

EXPOSE 8765

CMD ["python", "app.py"]
