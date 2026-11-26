import "@typespec/http";

using TypeSpec.Http;

@service(#{ title: "Flight Booking API" })
namespace FlightBookingApi;

model City {
  code: string;
  name: string;
  country?: string;
}

model Money {
  /** Amount in whole rubles. */
  amount: integer;

  currency: "RUB";
}

model Airline {
  code: string;
  name: string;
}

model Flight {
  /** Flight identifier without whitespace or slashes. */
  @pattern("^[^\\s/]+$")
  id: string;

  flightNumber: string;
  airline: Airline;
  origin: City;
  destination: City;

  /** Departure time in UTC. */
  departureAt: utcDateTime;

  /** Arrival time in UTC. */
  arrivalAt: utcDateTime;

  durationMinutes: integer;

  /** Price per passenger. */
  price: Money;

  seatsAvailable: integer;
}

model Passenger {
  @minLength(1)
  firstName: string;

  @minLength(1)
  lastName: string;

  dateOfBirth: plainDate;

  @minLength(1)
  documentNumber: string;
}

model Contact {
  @minLength(1)
  email: string;

  @minLength(1)
  phone: string;
}

model Booking {
  @pattern("^[A-Z0-9]{6}$")
  code: string;

  status: "confirmed" | "cancelled";
  flight: Flight;
  passengers: Passenger[];
  contact: Contact;

  /** Flight price multiplied by the number of passengers. */
  totalPrice: Money;

  /** Booking creation time in UTC. */
  createdAt: utcDateTime;
}

model CreateBookingRequest {
  @minLength(1)
  flightId: string;

  contact: Contact;

  @minItems(1)
  passengers: Passenger[];
}

model CancelBookingRequest {
  lastName: string;
}

model HealthResponse {
  status: "ok";
}

model ErrorResponse<Code extends string = string> {
  /** validation_error for HTTP 400 and not_found for HTTP 404. */
  code: Code;

  message: string;
}

model ValidationError is ErrorResponse<"validation_error">;
model NotFoundError is ErrorResponse<"not_found">;

model ValidationErrorResponse {
  @statusCode statusCode: 400;
  @body body: ValidationError;
}

model ResourceNotFoundResponse {
  @statusCode statusCode: 404;
  @body body: NotFoundError;
}

model BookingCreatedResponse {
  @statusCode statusCode: 201;
  @body body: Booking;
}

@route("/api/health")
interface Health {
  /** Check whether the application is responding without checking dependencies. */
  @get check(): HealthResponse;
}

@route("/api/cities")
interface Cities {
  @get list(): City[];
}

@route("/api/flights")
interface Flights {
  /** Search flights by route and departure date, returning an empty array when none match. */
  @get search(
    @query @minLength(1) origin: string,
    @query @minLength(1) destination: string,
    @query date: plainDate,
    @query @minValue(1) passengers?: integer = 1,
  ): Flight[] | ValidationErrorResponse;

  /** Get a flight by its identifier. */
  @get
  @route("/{id}")
  get(@path id: string): Flight | ResourceNotFoundResponse;
}

@route("/api/bookings")
interface Bookings {
  /** Create a booking; the server calculates its total price. */
  @post create(@body booking: CreateBookingRequest):
    | BookingCreatedResponse
    | ValidationErrorResponse;

  /**
   * Find a booking using its code and any passenger's surname.
   * The surname is compared without case or surrounding whitespace.
   * Missing or incorrect details return the same 404 response.
   */
  @get
  @route("/{code}")
  get(@path code: string, @query lastName?: string):
    | Booking
    | ResourceNotFoundResponse;

  /**
   * Cancel a booking using its code and a passenger's surname.
   * Returns the booking with status cancelled, including on repeated cancellation.
   */
  @post
  @route("/{code}/cancel")
  cancel(@path code: string, @body request: CancelBookingRequest):
    | Booking
    | ValidationErrorResponse
    | ResourceNotFoundResponse;
}
